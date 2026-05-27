<?php

namespace App\Mcp\Tools\Gmail;

use App\Models\EmailAccount;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('gmail_list_accounts')]
#[Description(
    'Return the canonical set of connected Google accounts (the valid values for every mail tool\'s `account` '
    .'parameter). Call this at the start of cross-account work. '
    .'If this returns an empty list, the user has no accounts configured — call gmail_setup_status to begin onboarding.'
)]
class ListAccountsTool extends Tool
{
    public function handle(): ResponseFactory
    {
        $accounts = EmailAccount::query()
            ->orderBy('email')
            ->get()
            ->map(fn (EmailAccount $account): array => [
                'email' => $account->email,
                'display_name' => $account->display_name,
                'scopes' => $account->scopes,
                'added_at' => $account->added_at?->toIso8601String(),
                'last_used_at' => $account->last_used_at?->toIso8601String(),
            ])
            ->all();

        return Response::structured([
            'accounts' => $accounts,
            'count' => count($accounts),
            ...($accounts === []
                ? ['suggestion' => 'No accounts configured. Call gmail_setup_status to begin onboarding.']
                : []),
        ]);
    }
}
