<?php

namespace App\Services;

use App\Models\AuditLog;
use Throwable;

/**
 * Writes the mutation audit trail. Call once per MUTATING tool (label,
 * unlabel, archive, draft, and setup mutations). Reads are never logged.
 *
 * Sensitive params are redacted before they touch the DB — notably the OAuth
 * client_secret and any draft body — so the audit log never becomes a
 * secondary store of secrets or message content.
 */
class AuditLogger
{
    /** Param keys whose values are replaced with a redaction marker. */
    private const REDACT = [
        'client_secret', 'client_id', 'refresh_token', 'access_token',
        'body', 'html_body', 'code',
    ];

    /**
     * @param  array<string, mixed>  $params
     * @param  list<string>|null  $threadIds
     */
    public function record(
        string $tool,
        ?string $account,
        array $params = [],
        ?array $threadIds = null,
        string $resultStatus = 'ok',
        ?string $errorMessage = null,
    ): void {
        AuditLog::create([
            'timestamp' => now(),
            'account' => $account,
            'tool' => $tool,
            'params' => $this->redact($params),
            'thread_ids' => $threadIds,
            'result_status' => $resultStatus,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Run a mutating operation, recording success or failure automatically.
     *
     * @template T
     *
     * @param  array<string, mixed>  $params
     * @param  list<string>|null  $threadIds
     * @param  callable(): T  $operation
     * @return T
     */
    public function around(
        string $tool,
        ?string $account,
        array $params,
        ?array $threadIds,
        callable $operation,
    ): mixed {
        try {
            $result = $operation();
        } catch (Throwable $e) {
            $this->record($tool, $account, $params, $threadIds, 'error', $e->getMessage());

            throw $e;
        }

        $this->record($tool, $account, $params, $threadIds, 'ok');

        return $result;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function redact(array $params): array
    {
        foreach ($params as $key => $value) {
            if (in_array($key, self::REDACT, true) && $value !== null && $value !== '') {
                $params[$key] = '[redacted]';
            }
        }

        return $params;
    }
}
