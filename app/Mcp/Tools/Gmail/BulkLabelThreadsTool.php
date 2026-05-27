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

#[Name('gmail_bulk_label_threads')]
#[Description(
    'Add the same label(s) to many threads at once (up to 1000), batched over Gmail\'s HTTP batch endpoint. '
    .'Returns a per-thread status map plus a summary. label_ids are label IDs (from gmail_list_labels). '
    .'Mutating: audited.'
)]
class BulkLabelThreadsTool extends Tool
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
            'label_ids' => ['required', 'array', 'min:1'],
            'label_ids.*' => ['string'],
        ]);

        /** @var list<string> $threadIds */
        $threadIds = array_values(array_unique($validated['thread_ids']));
        $labelIds = $validated['label_ids'];

        return $this->withGmail($account, function () use ($gmail, $audit, $account, $threadIds, $labelIds): ResponseFactory {
            $results = $gmail->modifyThreadsBatch($account, $threadIds, addLabelIds: $labelIds);
            $summary = $this->summarize($results);

            $audit->record(
                tool: 'gmail_bulk_label_threads',
                account: $account,
                params: ['label_ids' => $labelIds, 'count' => count($threadIds)],
                threadIds: $threadIds,
                resultStatus: $summary['status'],
                errorMessage: $summary['error_message'],
            );

            return $this->structured([
                'status' => $summary['status'],
                'labeled_count' => $summary['succeeded'],
                'failed_count' => $summary['failed'],
                'results' => $results,
            ]);
        });
    }

    /**
     * @param  array<string, array{status: string, error?: string}>  $results
     * @return array{status: string, succeeded: int, failed: int, error_message: ?string}
     */
    private function summarize(array $results): array
    {
        $succeeded = count(array_filter($results, fn (array $r): bool => $r['status'] === 'ok'));
        $failed = count($results) - $succeeded;

        return [
            'status' => match (true) {
                $failed === 0 => 'ok',
                $succeeded === 0 => 'error',
                default => 'partial',
            },
            'succeeded' => $succeeded,
            'failed' => $failed,
            'error_message' => $failed === 0 ? null : "{$failed} of ".count($results).' threads failed',
        ];
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
                ->description('Thread ids to label (max '.self::MAX_IDS.').')
                ->items($schema->string())
                ->min(1)
                ->max(self::MAX_IDS)
                ->required(),
            'label_ids' => $schema->array()
                ->description('Label IDs to add to every thread (from gmail_list_labels).')
                ->items($schema->string())
                ->min(1)
                ->required(),
        ];
    }
}
