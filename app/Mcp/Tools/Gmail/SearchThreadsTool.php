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

/**
 * Reference READ tool: validates account, guards onboarding state, and returns
 * cheap thread ids + snippets (no per-thread fetch, so it scales). Reads are
 * never written to the audit log.
 */
#[IsReadOnly]
#[Name('gmail_search_threads')]
#[Description(
    'Search a connected account\'s mail using Gmail query syntax (e.g. "from:boss is:unread newer_than:7d", '
    .'"label:invoices has:attachment"). Returns thread ids + snippets only — cheap and scalable. Use '
    .'gmail_get_thread for full message content, or pass the ids straight to a bulk_* tool. Page with page_token. '
    .'If the response is an error payload with a `suggestion`, follow it (e.g. onboarding or re-auth).'
)]
class SearchThreadsTool extends Tool
{
    use InteractsWithGmail;

    public function handle(Request $request, GmailClient $gmail): ResponseFactory
    {
        $account = (string) $request->get('account');

        if ($guard = $this->guard($account)) {
            return $guard;
        }

        $validated = $request->validate([
            'query' => ['required', 'string'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:500'],
            'page_token' => ['nullable', 'string'],
        ]);

        return $this->withGmail($account, fn (): ResponseFactory => $this->structured(
            $gmail->searchThreads(
                $account,
                $validated['query'],
                (int) ($validated['page_size'] ?? 25),
                $validated['page_token'] ?? null,
            )
        ));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account' => $schema->string()
                ->description('Email address of the connected account to search. See gmail_list_accounts.')
                ->required(),
            'query' => $schema->string()
                ->description('Gmail search query, same syntax as the Gmail search box.')
                ->required(),
            'page_size' => $schema->integer()
                ->description('Max threads to return (1-500). Default 25.')
                ->min(1)
                ->max(500),
            'page_token' => $schema->string()
                ->description('next_page_token from a previous call, to fetch the next page.'),
        ];
    }
}
