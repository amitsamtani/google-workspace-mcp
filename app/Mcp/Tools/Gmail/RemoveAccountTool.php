<?php

namespace App\Mcp\Tools\Gmail;

use App\Mcp\Concerns\InteractsWithGmail;
use App\Models\EmailAccount;
use App\Services\AuditLogger;
use App\Services\Gmail\GoogleClientFactory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Throwable;

#[IsDestructive]
#[Name('gmail_remove_account')]
#[Description(
    'Disconnect a Google account: revoke its refresh token at Google (best effort) and delete its stored row. '
    .'After this the account is no longer usable until re-added via gmail_start_oauth_flow.'
)]
class RemoveAccountTool extends Tool
{
    use InteractsWithGmail;

    public function handle(Request $request, AuditLogger $audit, GoogleClientFactory $factory): ResponseFactory
    {
        $account = (string) $request->get('account');

        if ($guard = $this->guard($account)) {
            return $guard;
        }

        $model = EmailAccount::query()->where('email', $account)->firstOrFail();

        // Best-effort revoke at Google; proceed with local deletion regardless.
        $revoked = false;
        try {
            $client = $factory->make($model->scopes);
            $revoked = (bool) $client->revokeToken($model->refresh_token);
        } catch (Throwable) {
            $revoked = false;
        }

        $model->delete();

        $audit->record(
            tool: 'gmail_remove_account',
            account: $account,
            params: ['account' => $account, 'revoked_at_google' => $revoked],
        );

        return $this->structured([
            'status' => 'removed',
            'account' => $account,
            'revoked_at_google' => $revoked,
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account' => $schema->string()
                ->description('Email address of the connected account to remove. See gmail_list_accounts.')
                ->required(),
        ];
    }
}
