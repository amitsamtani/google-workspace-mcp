<?php

namespace App\Providers;

use App\Services\Gmail\GmailClient;
use App\Services\Gmail\TokenBucketLimiter;
use App\Services\Google\AccountTokenManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singletons so the in-memory access-token cache (AccountTokenManager)
        // and the rate-limit buckets (TokenBucketLimiter) persist across tool
        // calls within a single long-lived stdio session.
        $this->app->singleton(TokenBucketLimiter::class);
        $this->app->singleton(AccountTokenManager::class);
        $this->app->singleton(GmailClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // WAL lets concurrent Claude sessions (each a separate stdio process
        // sharing data.sqlite) read while one writes, avoiding most
        // "database is locked" errors.
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA journal_mode=WAL');
            DB::statement('PRAGMA busy_timeout=5000');
        }
    }
}
