<?php

namespace App\Console\Commands;

use App\Models\OauthCredential;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

/**
 * Save the BYOGCP OAuth Client ID/Secret from the shell, keeping the secret
 * out of any chat transcript AND out of shell history (it is read via a hidden
 * prompt, not passed as an argument). The in-chat gmail_save_oauth_credentials
 * tool remains for the conversational onboarding path.
 */
class SaveCredentialsCommand extends Command
{
    protected $signature = 'gworkspace:save-credentials';

    protected $description = 'Store the Google OAuth Client ID/Secret (encrypted), via a hidden prompt.';

    public function handle(AuditLogger $audit): int
    {
        $clientId = trim((string) $this->ask('OAuth Client ID (ends with .apps.googleusercontent.com)'));
        $clientSecret = trim((string) $this->secret('OAuth Client Secret (hidden)'));

        if (! str_ends_with($clientId, '.apps.googleusercontent.com')) {
            $this->error('Client ID must end with .apps.googleusercontent.com — copy it from your Desktop OAuth client.');

            return self::FAILURE;
        }

        if ($clientSecret === '') {
            $this->error('Client Secret cannot be empty.');

            return self::FAILURE;
        }

        OauthCredential::query()->delete();
        OauthCredential::create(['client_id' => $clientId, 'client_secret' => $clientSecret]);

        $audit->record(
            tool: 'gworkspace:save-credentials',
            account: null,
            params: ['client_id' => $clientId, 'client_secret' => $clientSecret],
        );

        $this->info('Saved (encrypted). Next: in a Claude session, ask to connect an account — it will call gmail_start_oauth_flow.');

        return self::SUCCESS;
    }
}
