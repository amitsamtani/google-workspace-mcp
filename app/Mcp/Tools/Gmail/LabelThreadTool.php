<?php

namespace App\Mcp\Tools\Gmail;

use App\Mcp\Concerns\InteractsWithGoogleApi;
use App\Services\AuditLogger;
use App\Services\Gmail\GmailClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('gmail_label_thread')]
#[Description(
    'Add one or more labels to a single thread. label_ids are label IDs (from gmail_list_labels), not names. '
    .'For many threads at once use gmail_bulk_label_threads. Mutating: audited.'
)]
class LabelThreadTool extends Tool
{
    use InteractsWithGoogleApi;

    public function handle(Request $request, GmailClient $gmail, AuditLogger $audit): ResponseFactory
    {
        $account = (string) $request->get('account');

        if ($guard = $this->guard($account)) {
            return $guard;
        }

        $validated = $request->validate([
            'thread_id' => ['required', 'string'],
            'label_ids' => ['required', 'array', 'min:1'],
            'label_ids.*' => ['string'],
        ]);

        return $this->withGoogle($account, function () use ($gmail, $audit, $account, $validated): ResponseFactory {
            $audit->around(
                tool: 'gmail_label_thread',
                account: $account,
                params: ['label_ids' => $validated['label_ids']],
                threadIds: [$validated['thread_id']],
                operation: fn () => $gmail->modifyThread($account, $validated['thread_id'], addLabelIds: $validated['label_ids']),
            );

            return $this->structured(['status' => 'ok', 'thread_id' => $validated['thread_id'], 'added' => $validated['label_ids']]);
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
            'thread_id' => $schema->string()
                ->description('Thread id to label.')
                ->required(),
            'label_ids' => $schema->array()
                ->description('Label IDs to add (from gmail_list_labels).')
                ->items($schema->string())
                ->required(),
        ];
    }
}
