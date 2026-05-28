<?php

namespace App\Console\Commands;

use App\Models\EmailAccount;
use App\Services\AuditLogger;
use App\Services\Gmail\GoogleClientFactory;
use App\Services\Gmail\OauthFlowManager;
use App\Services\Google\Scopes;
use Google\Service\Gmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Internal: the detached loopback OAuth callback listener.
 *
 * Spawned in the background by OauthFlowManager::start(). It owns the probed
 * loopback port, waits up to 5 minutes for the browser redirect, exchanges the
 * authorization code for tokens, stores the account, and records the flow
 * outcome in the shared cache for gmail_complete_oauth_flow to read.
 *
 * Not meant to be run by hand — hence hidden.
 */
class OauthListenCommand extends Command
{
    protected $signature = 'gworkspace:oauth-listen {--state= : OAuth flow state token} {--port= : Loopback port to bind}';

    protected $description = 'Internal: listen for the loopback OAuth callback for a pending flow.';

    protected $hidden = true;

    public function handle(GoogleClientFactory $factory, AuditLogger $audit): int
    {
        $state = (string) $this->option('state');
        $port = (int) $this->option('port');
        $key = OauthFlowManager::cacheKey($state);

        if ($state === '' || $port === 0 || Cache::get($key) === null) {
            return self::FAILURE;
        }

        $server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
        if ($server === false) {
            $this->markFailed($key, "Could not bind loopback port {$port}: {$errstr}");

            return self::FAILURE;
        }

        $deadline = time() + OauthFlowManager::TIMEOUT_SECONDS;

        try {
            $code = $this->awaitCode($server, $state, $deadline);
        } finally {
            fclose($server);
        }

        if ($code === null) {
            $this->markTimeout($key);

            return self::SUCCESS;
        }

        try {
            $client = $factory->make(Scopes::requested(), OauthFlowManager::redirectUri($port));
            $token = $client->fetchAccessTokenWithAuthCode($code);

            if (isset($token['error']) || empty($token['refresh_token'])) {
                $this->markFailed($key, $token['error_description'] ?? $token['error']
                    ?? 'No refresh token returned. Ensure the consent screen is configured for offline access.');

                return self::SUCCESS;
            }

            $client->setAccessToken($token);
            // gmail.modify covers users.getProfile (the canonical way to learn
            // the account's email after consent). If the user declined the
            // gmail scope, this will fail and we surface that.
            $email = (new Gmail($client))->users->getProfile('me')->getEmailAddress();

            // Store whatever scopes Google actually granted (may be a subset
            // of what we requested if the user de-checked any), so per-tool
            // scope guards reflect reality.
            $grantedScopes = Scopes::fromTokenResponse($token['scope'] ?? null);

            EmailAccount::updateOrCreate(
                ['email' => $email],
                [
                    'refresh_token' => $token['refresh_token'],
                    'scopes' => $grantedScopes,
                    'added_at' => now(),
                    'last_used_at' => now(),
                ],
            );

            $audit->record(tool: 'gmail_start_oauth_flow', account: $email, params: ['action' => 'add_account']);

            Cache::put($key, ['status' => 'completed', 'account' => $email], OauthFlowManager::cacheTtl());
        } catch (Throwable $e) {
            $this->markFailed($key, $e->getMessage());
        }

        return self::SUCCESS;
    }

    /**
     * Accept connections until we get one carrying the authorization code (and
     * matching state), or the deadline passes. Other requests (e.g. favicon)
     * get a 404 and we keep waiting.
     */
    private function awaitCode($server, string $state, int $deadline): ?string
    {
        while (time() < $deadline) {
            $conn = @stream_socket_accept($server, 5);
            if ($conn === false) {
                continue;
            }

            $requestLine = (string) fgets($conn);
            $query = [];
            if (preg_match('#^GET\s+/\?([^\s]*)#', $requestLine, $m) === 1) {
                parse_str($m[1], $query);
            }

            if (isset($query['error'])) {
                $this->respond($conn, 'Authorization was denied. You can close this tab and return to your terminal.');

                return null;
            }

            if (isset($query['code'], $query['state']) && hash_equals($state, (string) $query['state'])) {
                $this->respond($conn, 'Account connected. You can close this tab and return to your terminal.');

                return (string) $query['code'];
            }

            // Not the callback (favicon, etc.) — acknowledge and keep waiting.
            $this->respond($conn, 'Waiting for authorization...', '404 Not Found');
        }

        return null;
    }

    private function respond($conn, string $message, string $status = '200 OK'): void
    {
        $html = '<!doctype html><html><head><meta charset=utf-8><title>google-workspace-mcp</title></head>'
            .'<body style="font-family:system-ui;max-width:32rem;margin:4rem auto;text-align:center">'
            ."<h2>google-workspace-mcp</h2><p>{$message}</p></body></html>";

        $response = "HTTP/1.1 {$status}\r\n"
            ."Content-Type: text/html; charset=utf-8\r\n"
            .'Content-Length: '.strlen($html)."\r\n"
            ."Connection: close\r\n\r\n".$html;

        fwrite($conn, $response);
        fclose($conn);
    }

    private function markFailed(string $key, string $message): void
    {
        Cache::put($key, ['status' => 'failed', 'message' => $message], OauthFlowManager::cacheTtl());
    }

    private function markTimeout(string $key): void
    {
        Cache::put($key, ['status' => 'timeout'], OauthFlowManager::cacheTtl());
    }
}
