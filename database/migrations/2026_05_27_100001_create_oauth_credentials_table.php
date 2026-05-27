<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the user's Bring-Your-Own Google Cloud OAuth client.
 *
 * A single row is expected in v1; the table is intentionally multi-row so a
 * future "multiple GCP projects" feature can slot in without a migration.
 * client_id / client_secret are written through Laravel's `encrypted` cast,
 * so the columns hold ciphertext, never the raw values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_credentials', function (Blueprint $table) {
            $table->id();
            $table->text('client_id');     // encrypted
            $table->text('client_secret'); // encrypted
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_credentials');
    }
};
