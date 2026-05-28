<?php

namespace App\Services\Google;

use App\Exceptions\ReauthRequiredException;
use App\Models\EmailAccount;
use App\Services\Gmail\GoogleClientFactory;
use App\Services\Gmail\TokenBucketLimiter;
use Google\Client as GoogleClient;
use Google\Service\Exception as GoogleServiceException;
use Throwable;

/**
 * Per-account access-token management shared by every Google API client
 * (Gmail, Calendar, …). One access token carries all granted scopes, so one
 * cache per account email serves the whole server.
 *
 * Registered as a singleton so the cache survives across tool calls within a
 * single stdio session. Was originally inside GmailClient; extracted here so
 * CalendarClient (and future DriveClient) reuse the same refresh/retry/rate-
 * limit logic without duplicating it or maintaining parallel caches.
 */
class AccountTokenManager
{
    /** Refresh a token this many seconds before its real expiry. */
    private const REFRESH_SKEW = 60;

    private const MAX_RETRIES = 3;

    /** Retryable HTTP statuses on Google APIs. */
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    /** @var array<string, array{token: array<string, mixed>, expires_at: int}> */
    private array $tokenCache = [];

    public function __construct(
        private readonly GoogleClientFactory $factory,
        private readonly TokenBucketLimiter $limiter,
    ) {}

    /**
     * Return a Google client bearing a fresh access token for the account.
     * Refreshes from the stored refresh token when the cached token is near
     * expiry; throws ReauthRequiredException when Google rejects the refresh.
     *
     * @throws ReauthRequiredException
     */
    public function authenticatedClient(string $account): GoogleClient
    {
        $model = EmailAccount::query()->where('email', $account)->first();

        if ($model === null) {
            // Tools validate `account` first, so reaching here means a race
            // (e.g. removed mid-session). Treat as needing re-auth.
            throw new ReauthRequiredException($account, 'account is not connected');
        }

        $client = $this->factory->make($model->scopes ?: Scopes::requested());

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
     * Execute an operation through the rate limiter with exponential backoff
     * on 429/5xx (max 3 retries).
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public function call(string $account, int $cost, callable $operation): mixed
    {
        $this->limiter->consume($account, $cost);

        $attempt = 0;
        while (true) {
            try {
                return $operation();
            } catch (GoogleServiceException $e) {
                $attempt++;
                if ($attempt > self::MAX_RETRIES || ! in_array($e->getCode(), self::RETRYABLE_STATUSES, true)) {
                    throw $e;
                }

                // Exponential backoff with jitter: ~0.4s, 0.8s, 1.6s.
                usleep((int) ((2 ** $attempt) * 200_000 + random_int(0, 250_000)));
            }
        }
    }
}
