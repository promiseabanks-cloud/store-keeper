<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Pos;

use DateTimeImmutable;
use DateTimeZone;
use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;

/**
 * A fixed-window request limiter for the POS endpoints (§15.1, D-041).
 *
 * State lives in `pos_rate_limits` rather than a transient so a limiter survives
 * an object-cache flush and is shared by every PHP worker. Each bucket is one
 * row: `window_start`, `hits` and `expires_at`. The increment is a single
 * upsert, so two concurrent requests cannot both read "under the limit".
 */
final class RateLimiter
{
    /** Requests one registered device may make in a minute across POS/sync. */
    public const DEVICE_REQUESTS_PER_MINUTE = 120;

    /** Failed device-token attempts one IP may make in a minute. */
    public const AUTH_FAILURES_PER_MINUTE = 20;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Count one hit against a bucket.
     *
     * @return array{allowed:bool,limit:int,remaining:int,retry_after:int,reset_at:string}
     */
    public function hit(string $bucket, int $limit, int $windowSeconds = 60): array
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $windowStart = $now->format('Y-m-d H:i:s.v');
        $expiresAt = $now->modify(sprintf('+%d seconds', max(1, $windowSeconds)))->format('Y-m-d H:i:s.v');
        $cutoff = $now->modify(sprintf('-%d seconds', max(1, $windowSeconds)))->format('Y-m-d H:i:s.v');

        // The three assignments matter in order: `hits` reads the old window,
        // `window_start` resets only when that window has expired, and
        // `expires_at` can only ever move forward.
        $this->db->execute(
            sprintf(
                'INSERT INTO %spos_rate_limits (bucket, window_start, hits, expires_at)
                 VALUES (?, ?, 1, ?)
                 ON DUPLICATE KEY UPDATE
                   hits = IF(window_start <= ?, 1, hits + 1),
                   window_start = IF(window_start <= ?, VALUES(window_start), window_start),
                   expires_at = GREATEST(expires_at, VALUES(expires_at))',
                $this->db->prefix()
            ),
            [$bucket, $windowStart, $expiresAt, $cutoff, $cutoff]
        );

        $row = $this->db->first(
            sprintf('SELECT hits, window_start, expires_at FROM %spos_rate_limits WHERE bucket = ?', $this->db->prefix()),
            [$bucket]
        ) ?? ['hits' => 1, 'window_start' => $windowStart, 'expires_at' => $expiresAt];

        $hits = (int) $row['hits'];
        $allowed = $hits <= $limit;
        $retryAfter = 0;

        if (!$allowed) {
            $started = new DateTimeImmutable((string) $row['window_start'], new DateTimeZone('UTC'));
            $retryAfter = max(1, max(1, $windowSeconds) - max(0, $now->getTimestamp() - $started->getTimestamp()));
        }

        return [
            'allowed' => $allowed,
            'limit' => $limit,
            'remaining' => max(0, $limit - $hits),
            'retry_after' => $retryAfter,
            'reset_at' => (string) $row['expires_at'],
        ];
    }

    /** Drop expired buckets; called by the hourly device-check cron. */
    public function prune(): int
    {
        return $this->db->execute(
            sprintf('DELETE FROM %spos_rate_limits WHERE expires_at < ?', $this->db->prefix()),
            [$this->clock->nowString()]
        );
    }
}
