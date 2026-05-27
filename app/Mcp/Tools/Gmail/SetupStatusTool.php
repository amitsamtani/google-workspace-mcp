<?php

namespace App\Mcp\Tools\Gmail;

use App\Mcp\Support\GmailWizard;
use App\Models\EmailAccount;
use App\Models\OauthCredential;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('gmail_setup_status')]
#[Description(
    'Report the current setup state: whether OAuth credentials are stored, which accounts are connected, '
    .'and the next_step to take (create_gcp_project | save_credentials | add_account | ready) with human-readable '
    .'instructions. Call this whenever another gmail_* tool returns "not configured", to drive onboarding.'
)]
class SetupStatusTool extends Tool
{
    public function handle(): ResponseFactory
    {
        $next = GmailWizard::nextStep();

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
            'has_oauth_credentials' => OauthCredential::configured(),
            'accounts' => $accounts,
            'next_step' => $next['next_step'],
            'instructions' => $next['instructions'],
        ]);
    }
}
