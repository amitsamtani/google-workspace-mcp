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

#[Name('gmail_archive_thread')]
#[Description(
    'Archive a single thread by removing the INBOX label (a semantic alias for unlabelling INBOX). The thread is '
    .'NOT deleted — this server cannot delete or trash mail. For many threads use gmail_bulk_archive_threads. '
    .'Mutating: audited.'
)]
class ArchiveThreadTool extends Tool
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
        ]);

        return $this->withGoogle($account, function () use ($gmail, $audit, $account, $validated): ResponseFactory {
            $audit->around(
                tool: 'gmail_archive_thread',
                account: $account,
                params: [],
                threadIds: [$validated['thread_id']],
                operation: fn () => $gmail->modifyThread($account, $validated['thread_id'], removeLabelIds: ['INBOX']),
            );

            return $this->structured(['status' => 'archived', 'thread_id' => $validated['thread_id']]);
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
                ->description('Thread id to archive.')
                ->required(),
        ];
    }
}
