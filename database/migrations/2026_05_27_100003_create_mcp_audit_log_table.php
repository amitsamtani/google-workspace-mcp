<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail of every MUTATING tool call (label / unlabel /
 * archive / draft, plus setup mutations like save_credentials, add_account,
 * remove_account). Reads are never logged.
 *
 * `params` stores the tool arguments with secrets redacted by AuditLogger.
 * Query it via `php artisan gworkspace:audit`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_audit_log', function (Blueprint $table) {
            $table->id();
            $table->timestamp('timestamp')->index();
            $table->string('account')->nullable()->index();
            $table->string('tool')->index();
            $table->json('params')->nullable();
            $table->json('thread_ids')->nullable();
            $table->string('result_status'); // ok | partial | error
            $table->text('error_message')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_audit_log');
    }
};
