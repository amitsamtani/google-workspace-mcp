<?php

namespace App\Mcp\Tools\Gmail;

use App\Mcp\Concerns\InteractsWithGmail;
use App\Services\AuditLogger;
use App\Services\Gmail\GmailClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * Reference MUTATING + BATCH + AUDITED tool.
 *
 * Archives up to 1000 threads in one call by removing INBOX. Under the hood
 * GmailClient batches threads.modify over Gmail's HTTP batch endpoint (100 per
 * round trip), so we get TRUE per-thread status — unlike messages.batchModify,
 * which returns 204 with no per-id detail. The audit row records every thread
 * id and a result_status of ok / partial / error.
 */
#[Name('gmail_bulk_archive_threads')]
#[Description(
    'Archive many threads at once (remove INBOX), up to 1000 thread ids per call. Returns a per-thread status map '
    .'so you can see exactly which ids succeeded or failed, plus a summary. Threads are NOT deleted. This is the '
    .'scalable way to clear an inbox — prefer it over calling gmail_archive_thread in a loop. Mutating: audited.'
)]
class BulkArchiveThreadsTool extends Tool
{
    use InteractsWithGmail;

    private const MAX_IDS = 1000;

    public function handle(Request $request, GmailClient $gmail, AuditLogger $audit): ResponseFactory
    {
        $account = (string) $request->get('account');

        if ($guard = $this->guard($account)) {
            return $guard;
        }

        $validated = $request->validate([
            'thread_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_IDS],
            'thread_ids.*' => ['string'],
        ]);

        /** @var list<string> $threadIds */
        $threadIds = array_values(array_unique($validated['thread_ids']));

        return $this->withGmail($account, function () use ($gmail, $audit, $account, $threadIds): ResponseFactory {
            $results = $gmail->modifyThreadsBatch($account, $threadIds, removeLabelIds: ['INBOX']);

            $succeeded = array_keys(array_filter($results, fn (array $r): bool => $r['status'] === 'ok'));
            $failed = array_diff(array_keys($results), $succeeded);

            $status = match (true) {
                $failed === [] => 'ok',
                $succeeded === [] => 'error',
                default => 'partial',
            };

            $audit->record(
                tool: 'gmail_bulk_archive_threads',
                account: $account,
                params: ['count' => count($threadIds)],
                threadIds: $threadIds,
                resultStatus: $status,
                errorMessage: $failed === [] ? null : count($failed).' of '.count($threadIds).' threads failed',
            );

            return $this->structured([
                'status' => $status,
                'archived_count' => count($succeeded),
                'failed_count' => count($failed),
                'results' => $results,
            ]);
        });
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account' => $schema->string()
                ->description('Email address of the connected account. See gmail_list_accounts.')
                ->required(),
            'thread_ids' => $schema->array()
                ->description('Thread ids to archive (max '.self::MAX_IDS.'). Typically from gmail_search_threads.')
                ->items($schema->string())
                ->min(1)
                ->max(self::MAX_IDS)
                ->required(),
        ];
    }
}
