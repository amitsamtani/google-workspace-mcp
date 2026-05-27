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

#[Name('gmail_bulk_unlabel_threads')]
#[Description(
    'Remove the same label(s) from many threads at once (up to 1000), batched over Gmail\'s HTTP batch endpoint. '
    .'Returns a per-thread status map plus a summary. label_ids are label IDs (from gmail_list_labels). To bulk '
    .'archive, prefer gmail_bulk_archive_threads. Mutating: audited.'
)]
class BulkUnlabelThreadsTool extends Tool
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
            $results = $gmail->modifyThreadsBatch($account, $threadIds, removeLabelIds: $labelIds);

            $succeeded = count(array_filter($results, fn (array $r): bool => $r['status'] === 'ok'));
            $failed = count($results) - $succeeded;
            $status = match (true) {
                $failed === 0 => 'ok',
                $succeeded === 0 => 'error',
                default => 'partial',
            };

            $audit->record(
                tool: 'gmail_bulk_unlabel_threads',
                account: $account,
                params: ['label_ids' => $labelIds, 'count' => count($threadIds)],
                threadIds: $threadIds,
                resultStatus: $status,
                errorMessage: $failed === 0 ? null : "{$failed} of ".count($threadIds).' threads failed',
            );

            return $this->structured([
                'status' => $status,
                'unlabeled_count' => $succeeded,
                'failed_count' => $failed,
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
                ->description('Thread ids to modify (max '.self::MAX_IDS.').')
                ->items($schema->string())
                ->min(1)
                ->max(self::MAX_IDS)
                ->required(),
            'label_ids' => $schema->array()
                ->description('Label IDs to remove from every thread (from gmail_list_labels).')
                ->items($schema->string())
                ->min(1)
                ->required(),
        ];
    }
}
