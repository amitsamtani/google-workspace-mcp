<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

/**
 * Query the mutation audit log from the shell.
 *
 *   php artisan gworkspace:audit
 *   php artisan gworkspace:audit --account=amit@example.com --tool=gmail_bulk_archive_threads
 *   php artisan gworkspace:audit --since="2026-05-01"
 */
class AuditCommand extends Command
{
    protected $signature = 'gworkspace:audit
        {--account= : Filter by account email}
        {--tool= : Filter by tool name (e.g. gmail_bulk_archive_threads)}
        {--since= : Only entries at/after this date/time (any strtotime format)}
        {--limit=50 : Max rows to show}';

    protected $description = 'Query the MCP mutation audit log.';

    public function handle(): int
    {
        $query = AuditLog::query()->orderByDesc('timestamp');

        if ($account = $this->option('account')) {
            $query->where('account', $account);
        }

        if ($tool = $this->option('tool')) {
            $query->where('tool', $tool);
        }

        if ($since = $this->option('since')) {
            $query->where('timestamp', '>=', date('Y-m-d H:i:s', strtotime((string) $since)));
        }

        $rows = $query->limit((int) $this->option('limit'))->get();

        if ($rows->isEmpty()) {
            $this->info('No audit entries match.');

            return self::SUCCESS;
        }

        $this->table(
            ['Time', 'Account', 'Tool', 'Status', '#Threads', 'Error'],
            $rows->map(fn (AuditLog $r): array => [
                $r->timestamp?->toDateTimeString(),
                $r->account ?? '—',
                $r->tool,
                $r->result_status,
                is_array($r->thread_ids) ? count($r->thread_ids) : '—',
                $r->error_message ? str($r->error_message)->limit(40) : '',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
