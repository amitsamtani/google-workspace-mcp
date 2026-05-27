<?php

namespace App\Mcp\Tools\Gmail;

use App\Mcp\Concerns\InteractsWithGmail;
use App\Services\Gmail\GmailClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('gmail_get_thread')]
#[Description(
    'Fetch a single thread\'s full content: each message\'s headers (from/to/subject/date), label ids, snippet, '
    .'and plaintext body. Use after gmail_search_threads when you need to read a conversation.'
)]
class GetThreadTool extends Tool
{
    use InteractsWithGmail;

    public function handle(Request $request, GmailClient $gmail): ResponseFactory
    {
        $account = (string) $request->get('account');

        if ($guard = $this->guard($account)) {
            return $guard;
        }

        $validated = $request->validate([
            'thread_id' => ['required', 'string'],
        ]);

        return $this->withGmail($account, fn (): ResponseFactory => $this->structured(
            $gmail->getThread($account, $validated['thread_id'])
        ));
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
                ->description('Thread id, e.g. from gmail_search_threads.')
                ->required(),
        ];
    }
}
