<?php

namespace App\Services\Gmail;

use App\Exceptions\ReauthRequiredException;
use App\Models\EmailAccount;
use Google\Client as GoogleClient;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail;
use Google\Service\Gmail\Draft;
use Google\Service\Gmail\Label;
use Google\Service\Gmail\LabelColor;
use Google\Service\Gmail\Message;
use Google\Service\Gmail\MessagePart;
use Google\Service\Gmail\MessagePartHeader;
use Google\Service\Gmail\ModifyThreadRequest;
use Throwable;

/**
 * Per-account Gmail operations: refresh-token aware, rate-limited, retrying.
 *
 * Registered as a singleton so the in-memory access-token cache survives
 * across tool calls within one stdio session. Tokens are refreshed ~60s
 * before expiry. On refresh failure a ReauthRequiredException is thrown so
 * tools can surface the reauth_needed suggestion.
 *
 * Bulk thread mutations use Gmail's HTTP batch endpoint (100 threads.modify
 * sub-requests per round trip), which gives true per-thread status — unlike
 * messages.batchModify, which returns 204 with no per-id detail.
 */
class GmailClient
{
    /** Refresh a token this many seconds before its real expiry. */
    private const REFRESH_SKEW = 60;

    /** Max threads.modify sub-requests per HTTP batch (Google's guidance). */
    private const BATCH_CHUNK = 100;

    private const MAX_RETRIES = 3;

    /** @var array<string, array{token: array<string, mixed>, expires_at: int}> */
    private array $tokenCache = [];

    public function __construct(
        private readonly GoogleClientFactory $factory,
        private readonly TokenBucketLimiter $limiter,
    ) {}

    // ----- Reads -------------------------------------------------------------

    /**
     * @return array{threads: list<array{id: string, snippet: string}>, next_page_token: ?string, result_size_estimate: int}
     */
    public function searchThreads(string $account, string $query, int $pageSize = 25, ?string $pageToken = null): array
    {
        $service = $this->gmail($account);
        $params = ['maxResults' => max(1, min($pageSize, 500)), 'q' => $query];

        if ($pageToken !== null && $pageToken !== '') {
            $params['pageToken'] = $pageToken;
        }

        $response = $this->call($account, 5, fn () => $service->users_threads->listUsersThreads('me', $params));

        $threads = [];
        foreach ($response->getThreads() ?? [] as $thread) {
            $threads[] = ['id' => $thread->getId(), 'snippet' => (string) $thread->getSnippet()];
        }

        return [
            'threads' => $threads,
            'next_page_token' => $response->getNextPageToken() ?: null,
            'result_size_estimate' => (int) $response->getResultSizeEstimate(),
        ];
    }

    /**
     * @return array<string, mixed> the thread with its messages parsed into headers + plaintext body
     */
    public function getThread(string $account, string $threadId): array
    {
        $service = $this->gmail($account);
        $thread = $this->call($account, 10, fn () => $service->users_threads->get('me', $threadId, ['format' => 'full']));

        $messages = [];
        foreach ($thread->getMessages() ?? [] as $message) {
            $headers = $this->headerMap($message->getPayload()?->getHeaders() ?? []);
            $messages[] = [
                'id' => $message->getId(),
                'label_ids' => $message->getLabelIds() ?? [],
                'snippet' => (string) $message->getSnippet(),
                'from' => $headers['from'] ?? null,
                'to' => $headers['to'] ?? null,
                'subject' => $headers['subject'] ?? null,
                'date' => $headers['date'] ?? null,
                'message_id_header' => $headers['message-id'] ?? null,
                'body_text' => $this->extractPlainText($message),
            ];
        }

        return ['id' => $thread->getId(), 'messages' => $messages];
    }

    /**
     * @return list<array{id: string, name: string, type: string}>
     */
    public function listLabels(string $account): array
    {
        $service = $this->gmail($account);
        $response = $this->call($account, 1, fn () => $service->users_labels->listUsersLabels('me'));

        $labels = [];
        foreach ($response->getLabels() ?? [] as $label) {
            $labels[] = ['id' => $label->getId(), 'name' => $label->getName(), 'type' => (string) $label->getType()];
        }

        return $labels;
    }

    // ----- Mutations ---------------------------------------------------------

    /** @return array{id: string, name: string} */
    public function createLabel(string $account, string $name, ?string $backgroundColor = null, ?string $textColor = null): array
    {
        $service = $this->gmail($account);

        $label = new Label;
        $label->setName($name);
        $label->setLabelListVisibility('labelShow');
        $label->setMessageListVisibility('show');

        if ($backgroundColor !== null) {
            $color = new LabelColor;
            $color->setBackgroundColor($backgroundColor);
            $color->setTextColor($textColor ?? '#ffffff');
            $label->setColor($color);
        }

        $created = $this->call($account, 5, fn () => $service->users_labels->create('me', $label));

        return ['id' => $created->getId(), 'name' => $created->getName()];
    }

    /**
     * Modify labels on a single thread (used by archive / label / unlabel).
     *
     * @param  list<string>  $addLabelIds
     * @param  list<string>  $removeLabelIds
     */
    public function modifyThread(string $account, string $threadId, array $addLabelIds = [], array $removeLabelIds = []): void
    {
        $service = $this->gmail($account);
        $request = new ModifyThreadRequest;
        $request->setAddLabelIds($addLabelIds);
        $request->setRemoveLabelIds($removeLabelIds);

        $this->call($account, 10, fn () => $service->users_threads->modify('me', $threadId, $request));
    }

    /**
     * Bulk thread label changes via the Gmail HTTP batch endpoint. Returns a
     * per-thread status map so callers can report exactly which ids succeeded.
     *
     * @param  list<string>  $threadIds
     * @param  list<string>  $addLabelIds
     * @param  list<string>  $removeLabelIds
     * @return array<string, array{status: string, error?: string}> keyed by thread id
     */
    public function modifyThreadsBatch(string $account, array $threadIds, array $addLabelIds = [], array $removeLabelIds = []): array
    {
        $client = $this->authenticatedClient($account);
        $service = new Gmail($client);
        $results = [];

        foreach (array_chunk($threadIds, self::BATCH_CHUNK) as $chunk) {
            $this->limiter->consume($account, count($chunk) * 10);

            $client->setUseBatch(true);

            try {
                $batch = $service->createBatch();

                foreach ($chunk as $threadId) {
                    $request = new ModifyThreadRequest;
                    $request->setAddLabelIds($addLabelIds);
                    $request->setRemoveLabelIds($removeLabelIds);
                    $batch->add($service->users_threads->modify('me', $threadId, $request), $threadId);
                }

                $responses = $batch->execute();
            } finally {
                $client->setUseBatch(false);
            }

            foreach ($chunk as $threadId) {
                $outcome = $responses['response-'.$threadId] ?? $responses[$threadId] ?? null;

                $results[$threadId] = $outcome instanceof Throwable
                    ? ['status' => 'error', 'error' => $this->messageFor($outcome)]
                    : ['status' => 'ok'];
            }
        }

        return $results;
    }

    /**
     * Create a draft. With gmail.modify we can create but never send drafts.
     *
     * @param  list<string>  $to
     * @return array{draft_id: string, message_id: string, thread_id: ?string}
     */
    public function createDraft(
        string $account,
        array $to,
        string $subject,
        string $body,
        ?string $htmlBody = null,
        ?string $replyToMessageId = null,
    ): array {
        $service = $this->gmail($account);

        $threadId = null;
        $headers = [
            'To' => implode(', ', $to),
            'Subject' => $this->encodeHeader($subject),
        ];

        if ($replyToMessageId !== null && $replyToMessageId !== '') {
            $original = $this->call($account, 5, fn () => $service->users_messages->get('me', $replyToMessageId, ['format' => 'metadata']));
            $threadId = $original->getThreadId();
            $originalHeaders = $this->headerMap($original->getPayload()?->getHeaders() ?? []);

            if (isset($originalHeaders['message-id'])) {
                $headers['In-Reply-To'] = $originalHeaders['message-id'];
                $headers['References'] = trim(($originalHeaders['references'] ?? '').' '.$originalHeaders['message-id']);
            }
        }

        $message = new Message;
        $message->setRaw($this->encodeMime($headers, $body, $htmlBody));

        if ($threadId !== null) {
            $message->setThreadId($threadId);
        }

        $draft = new Draft;
        $draft->setMessage($message);

        $created = $this->call($account, 10, fn () => $service->users_drafts->create('me', $draft));

        return [
            'draft_id' => $created->getId(),
            'message_id' => $created->getMessage()?->getId() ?? '',
            'thread_id' => $threadId,
        ];
    }

    // ----- Auth & transport --------------------------------------------------

    private function gmail(string $account): Gmail
    {
        return new Gmail($this->authenticatedClient($account));
    }

    /**
     * Build a Google client bearing a valid access token for the account,
     * refreshing from the stored refresh token when the cached token is near
     * expiry.
     *
     * @throws ReauthRequiredException
     */
    private function authenticatedClient(string $account): GoogleClient
    {
        $model = EmailAccount::query()->where('email', $account)->first();

        if ($model === null) {
            // Tools validate `account` first, so reaching here means a race
            // (e.g. removed mid-session). Treat as needing re-auth.
            throw new ReauthRequiredException($account, 'account is not connected');
        }

        $client = $this->factory->make($model->scopes ?: GmailScopes::default());

        $cached = $this->tokenCache[$account] ?? null;
        if ($cached !== null && time() < $cached['expires_at'] - self::REFRESH_SKEW) {
            $client->setAccessToken($cached['token']);

            return $client;
        }

        try {
            $token = $client->fetchAccessTokenWithRefreshToken($model->refresh_token);
        } catch (Throwable $e) {
            throw new ReauthRequiredException($account, $e->getMessage());
        }

        if (isset($token['error'])) {
            throw new ReauthRequiredException($account, (string) ($token['error_description'] ?? $token['error']));
        }

        // Google may omit the refresh token on refresh; keep the stored one,
        // and persist it if it was rotated.
        if (empty($token['refresh_token'])) {
            $token['refresh_token'] = $model->refresh_token;
        } elseif ($token['refresh_token'] !== $model->refresh_token) {
            $model->forceFill(['refresh_token' => $token['refresh_token']])->save();
        }

        $this->tokenCache[$account] = [
            'token' => $token,
            'expires_at' => time() + (int) ($token['expires_in'] ?? 3600),
        ];

        $model->touchLastUsed();
        $client->setAccessToken($token);

        return $client;
    }

    /**
     * Execute a single API call through the rate limiter with exponential
     * backoff on 429/5xx (max 3 retries).
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function call(string $account, int $cost, callable $operation): mixed
    {
        $this->limiter->consume($account, $cost);

        $attempt = 0;
        while (true) {
            try {
                return $operation();
            } catch (GoogleServiceException $e) {
                $attempt++;
                if ($attempt > self::MAX_RETRIES || ! in_array($e->getCode(), [429, 500, 502, 503, 504], true)) {
                    throw $e;
                }

                // Exponential backoff with jitter: ~0.4s, 0.8s, 1.6s.
                usleep((int) ((2 ** $attempt) * 200_000 + random_int(0, 250_000)));
            }
        }
    }

    // ----- MIME / parsing helpers -------------------------------------------

    /**
     * @param  array<string, string>  $headers
     */
    private function encodeMime(array $headers, string $text, ?string $html): string
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $lines[] = 'MIME-Version: 1.0';

        if ($html !== null && $html !== '') {
            $boundary = 'gwmcp_'.bin2hex(random_bytes(8));
            $lines[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
            $lines[] = '';
            $lines[] = "--{$boundary}";
            $lines[] = 'Content-Type: text/plain; charset="UTF-8"';
            $lines[] = '';
            $lines[] = $text;
            $lines[] = "--{$boundary}";
            $lines[] = 'Content-Type: text/html; charset="UTF-8"';
            $lines[] = '';
            $lines[] = $html;
            $lines[] = "--{$boundary}--";
        } else {
            $lines[] = 'Content-Type: text/plain; charset="UTF-8"';
            $lines[] = '';
            $lines[] = $text;
        }

        return $this->base64Url(implode("\r\n", $lines));
    }

    /**
     * @param  array<int, MessagePartHeader>  $headers
     * @return array<string, string> lower-cased header name => value
     */
    private function headerMap(array $headers): array
    {
        $map = [];
        foreach ($headers as $header) {
            $map[strtolower($header->getName())] = $header->getValue();
        }

        return $map;
    }

    private function extractPlainText(Message $message): string
    {
        $payload = $message->getPayload();
        if ($payload === null) {
            return '';
        }

        // Recurse through the MIME tree: text/plain can be nested several
        // levels deep (e.g. multipart/mixed > multipart/alternative > text/plain
        // when there are attachments). Fall back to a stripped text/html part.
        $plain = $this->findPartData($payload, 'text/plain');
        if ($plain !== null) {
            return $plain;
        }

        $html = $this->findPartData($payload, 'text/html');

        return $html !== null ? trim(html_entity_decode(strip_tags($html))) : '';
    }

    /**
     * Depth-first search of the MIME tree for the decoded body of the first
     * part matching $mimeType.
     */
    private function findPartData(MessagePart $part, string $mimeType): ?string
    {
        if ($part->getMimeType() === $mimeType && $part->getBody()?->getData()) {
            return $this->base64UrlDecode($part->getBody()->getData());
        }

        foreach ($part->getParts() ?? [] as $child) {
            $found = $this->findPartData($child, $mimeType);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * RFC 2047-encode a header value if it contains non-ASCII characters, so
     * subjects like "Re: Façade quote" survive transport. Pure-ASCII values are
     * returned unchanged. Encoded words are kept short and folded to stay under
     * the 75-char limit without splitting multibyte characters.
     */
    private function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x00-\x7F]/', $value) !== 1) {
            return $value;
        }

        $words = array_map(
            static fn (string $chunk): string => '=?UTF-8?B?'.base64_encode($chunk).'?=',
            mb_str_split($value, 10, 'UTF-8'),
        );

        return implode("\r\n ", $words);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }

    private function messageFor(Throwable $e): string
    {
        return $e->getMessage();
    }
}
