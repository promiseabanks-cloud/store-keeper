<?php

declare(strict_types=1);

/**
 * §15.1, D-041: one row per throttled bucket (device or client IP).
 *
 * A fixed window is enough for the POS: the goal is to stop a leaked token or a
 * brute-force loop, not to shape traffic precisely. Expired rows are pruned by
 * the hourly device-check cron.
 */
return static function (string $prefix): array {
    return [
        "CREATE TABLE IF NOT EXISTS {p}pos_rate_limits (
            bucket VARCHAR(191) NOT NULL PRIMARY KEY,
            window_start DATETIME(3) NOT NULL,
            hits INT UNSIGNED NOT NULL DEFAULT 0,
            expires_at DATETIME(3) NOT NULL,
            KEY expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
};
