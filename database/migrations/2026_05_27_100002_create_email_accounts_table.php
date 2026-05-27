<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per connected Google Workspace account.
 *
 * `refresh_token` is stored through the `encrypted` cast. `scopes` is the JSON
 * list of OAuth scopes the token was granted (v1: gmail.modify only). The
 * email address is the canonical identifier used by every gmail_* tool's
 * required `account` parameter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('display_name')->nullable();
            $table->text('refresh_token'); // encrypted
            $table->json('scopes');
            $table->timestamp('added_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_accounts');
    }
};
