<?php

namespace App\Services\Gmail;

/**
 * In-memory, per-account token-bucket limiter for Gmail's per-user quota
 * (250 quota units/second; most ops cost ~5 units). Registered as a singleton
 * so the bucket persists across tool calls within a single stdio session.
 *
 * This is a politeness/throttle layer; the real safety net is the exponential
 * backoff on 429/5xx in GmailClient. Buckets are per-process, so concurrent
 * Claude sessions throttle independently — acceptable for a personal tool.
 */
class TokenBucketLimiter
{
    private const CAPACITY = 250;        // units

    private const REFILL_PER_SEC = 250;  // units/sec

    /** @var array<string, array{tokens: float, updated: float}> */
    private array $buckets = [];

    /**
     * Block (briefly) until `$cost` units are available for the account, then
     * consume them.
     */
    public function consume(string $account, int $cost): void
    {
        $cost = min($cost, self::CAPACITY);

        while (true) {
            $available = $this->refill($account);

            if ($available >= $cost) {
                $this->buckets[$account]['tokens'] = $available - $cost;

                return;
            }

            // Sleep just long enough to accrue the shortfall.
            $deficit = $cost - $available;
            usleep((int) (($deficit / self::REFILL_PER_SEC) * 1_000_000));
        }
    }

    private function refill(string $account): float
    {
        $now = microtime(true);
        $bucket = $this->buckets[$account] ?? ['tokens' => self::CAPACITY, 'updated' => $now];

        $elapsed = $now - $bucket['updated'];
        $tokens = min(self::CAPACITY, $bucket['tokens'] + $elapsed * self::REFILL_PER_SEC);

        $this->buckets[$account] = ['tokens' => $tokens, 'updated' => $now];

        return $tokens;
    }
}
