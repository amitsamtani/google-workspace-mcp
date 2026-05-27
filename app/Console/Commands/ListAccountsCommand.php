<?php

namespace App\Console\Commands;

use App\Models\EmailAccount;
use Illuminate\Console\Command;

/**
 * Shell equivalent of the gmail_list_accounts tool, for debugging without a
 * Claude session.
 */
class ListAccountsCommand extends Command
{
    protected $signature = 'gworkspace:list-accounts';

    protected $description = 'List connected Google accounts.';

    public function handle(): int
    {
        $accounts = EmailAccount::query()->orderBy('email')->get();

        if ($accounts->isEmpty()) {
            $this->warn('No accounts connected. Start Claude and ask it to set up Gmail, or run the in-chat wizard.');

            return self::SUCCESS;
        }

        $this->table(
            ['Email', 'Display name', 'Added', 'Last used'],
            $accounts->map(fn (EmailAccount $a): array => [
                $a->email,
                $a->display_name ?? '—',
                $a->added_at?->toDateTimeString() ?? '—',
                $a->last_used_at?->toDateTimeString() ?? '—',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
