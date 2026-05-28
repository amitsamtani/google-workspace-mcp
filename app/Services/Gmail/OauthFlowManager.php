<?php

namespace App\Services\Gmail;

use App\Services\Google\Scopes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Drives the loopback OAuth flow as two MCP steps so we never block the stdio
 * JSON-RPC loop for minutes:
 *
 *   1. start()  — probe a free loopback port, build the consent URL, and spawn
 *                 a DETACHED listener process (artisan gworkspace:oauth-listen)
 *                 that owns the port and waits up to 5 minutes for the browser
 *                 callback. Returns immediately.
 *   2. status() — read the flow's outcome (pending | added | failed | timeout)
 *                 from the shared cache, which the listener writes to.
 *
 * The cache is the database store (cross-process), so the stdio server and the
 * detached listener coordinate without a dedicated table.
 */
class OauthFlowManager
{
    public const TIMEOUT_SECONDS = 300;

    private const CACHE_TTL = 360;        // outlives TIMEOUT_SECONDS so results are readable

    private const PORT_START = 8765;

    private const PORT_SPAN = 64;

    public function __construct(private readonly GoogleClientFactory $factory) {}

    /**
     * @return array{consent_url: string, bound_port: int, state: string, expires_in_seconds: int}
     */
    public function start(?string $emailHint = null): array
    {
        $port = $this->findOpenPort();
        $state = Str::random(40);

        // Request the full bundle: one consent grants Gmail + Calendar (and
        // later Drive) for this account. The listener stores whatever was
        // actually granted off the token response.
        $client = $this->factory->make(Scopes::requested(), self::redirectUri($port));
        $client->setState($state);
        if ($emailHint !== null && $emailHint !== '') {
            $client->setLoginHint($emailHint);
        }

        Cache::put(self::cacheKey($state), [
            'status' => 'pending',
            'port' => $port,
            'email_hint' => $emailHint,
            'created_at' => now()->toIso8601String(),
        ], self::CACHE_TTL);
        Cache::put(self::latestKey(), $state, self::CACHE_TTL);

        $this->spawnListener($state, $port);

        return [
            'consent_url' => $client->createAuthUrl(),
            'bound_port' => $port,
            'state' => $state,
            'expires_in_seconds' => self::TIMEOUT_SECONDS,
        ];
    }

    /**
     * @return array<string, mixed> the current flow state
     */
    public function status(?string $state = null): array
    {
        $state ??= Cache::get(self::latestKey());

        if ($state === null) {
            return ['status' => 'unknown', 'message' => 'No OAuth flow has been started in this session.'];
        }

        return Cache::get(self::cacheKey($state)) ?? [
            'status' => 'expired',
            'message' => 'The OAuth flow expired or was never started.',
        ];
    }

    public static function cacheKey(string $state): string
    {
        return "oauth_flow:{$state}";
    }

    public static function latestKey(): string
    {
        return 'oauth_flow:latest';
    }

    public static function redirectUri(int $port): string
    {
        // Loopback IP redirect: Desktop OAuth clients accept 127.0.0.1 on any
        // port with no pre-registration.
        return "http://127.0.0.1:{$port}";
    }

    public static function cacheTtl(): int
    {
        return self::CACHE_TTL;
    }

    /**
     * Find a loopback TCP port we can bind, scanning upward from PORT_START.
     */
    private function findOpenPort(): int
    {
        for ($port = self::PORT_START; $port < self::PORT_START + self::PORT_SPAN; $port++) {
            $socket = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
            if ($socket !== false) {
                fclose($socket);

                return $port;
            }
        }

        throw new RuntimeException(
            'Could not find a free loopback port in the range '
            .self::PORT_START.'-'.(self::PORT_START + self::PORT_SPAN - 1).'.'
        );
    }

    /**
     * Launch the callback listener as a detached background process so this
     * tool call returns immediately while the listener waits for the browser.
     */
    private function spawnListener(string $state, int $port): void
    {
        // Detach all three std streams (< /dev/null, >> log 2>&1) so the
        // listener never holds the MCP server's stdio pipe — stdout is the
        // JSON-RPC channel and any inherited handle could corrupt/block it.
        $command = sprintf(
            'nohup %s %s gworkspace:oauth-listen --state=%s --port=%d < /dev/null >> %s 2>&1 &',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(base_path('artisan')),
            escapeshellarg($state),
            $port,
            escapeshellarg(storage_path('logs/oauth-listen.log')),
        );

        exec($command);
    }
}
