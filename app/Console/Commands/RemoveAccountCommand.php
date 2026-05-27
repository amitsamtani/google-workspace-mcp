<?php

namespace App\Console\Commands;

use App\Models\EmailAccount;
use App\Services\AuditLogger;
use App\Services\Gmail\GoogleClientFactory;
use Illuminate\Console\Command;
use Throwable;

/**
 * Shell equivalent of gmail_remove_account: revoke at Google (best effort) and
 * delete the stored row.
 */
class RemoveAccountCommand extends Command
{
    protected $signature = 'gworkspace:remove-account {email : The account email to disconnect}';

    protected $description = 'Disconnect a Google account (revoke + delete).';

    public function handle(GoogleClientFactory $factory, AuditLogger $audit): int
    {
        $email = (string) $this->argument('email');
        $model = EmailAccount::query()->where('email', $email)->first();

        if ($model === null) {
            $this->error("No connected account: {$email}");

            return self::FAILURE;
        }

        $revoked = false;
        try {
            $revoked = (bool) $factory->make($model->scopes)->revokeToken($model->refresh_token);
        } catch (Throwable $e) {
            $this->warn("Could not revoke at Google ({$e->getMessage()}); removing locally anyway.");
        }

        $model->delete();
        $audit->record(tool: 'gmail_remove_account', account: $email, params: ['account' => $email, 'revoked_at_google' => $revoked]);

        $this->info("Removed {$email}".($revoked ? ' (token revoked at Google).' : '.'));

        return self::SUCCESS;
    }
}
