<?php

declare(strict_types=1);

/**
 * §8.5, §15: the point-of-sale additions the system tables did not carry yet.
 *
 * - staff_pins: the 4–6 digit cashier PIN, hashed, with a short lockout.
 * - cash_ups: one row per device, cashier and business date (§15.1).
 * - change_log: the monotonically increasing cursor `/sync/pull` reads (§15.3).
 * - pos_sales: the device and cashier behind each POS order (§15.1).
 * - pos_returns: idempotency map for offline counter returns (§15.3 rule 1).
 * - refund_items: what a counter return put back, so a second return cannot
 *   exceed the original line.
 */
return static function (string $prefix): array {
    return [
        "CREATE TABLE IF NOT EXISTS {p}staff_pins (
            user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            pin_hash VARCHAR(255) NOT NULL,
            failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            locked_until DATETIME(3) NULL,
            set_by BIGINT UNSIGNED NULL,
            updated_at DATETIME(3) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}cash_ups (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            device_id BIGINT UNSIGNED NOT NULL,
            cashier_user_id BIGINT UNSIGNED NOT NULL,
            business_date DATE NOT NULL,
            opening_float_kobo BIGINT NOT NULL DEFAULT 0,
            expected_cash_kobo BIGINT NOT NULL,
            counted_cash_kobo BIGINT NOT NULL,
            difference_kobo BIGINT NOT NULL,
            note VARCHAR(255) NULL,
            created_at DATETIME(3) NOT NULL,
            UNIQUE KEY one_per_day (device_id, cashier_user_id, business_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}change_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            entity_type VARCHAR(32) NOT NULL,
            entity_id BIGINT UNSIGNED NULL,
            action VARCHAR(32) NOT NULL,
            payload JSON NULL,
            created_at DATETIME(3) NOT NULL,
            KEY entity (entity_type, entity_id),
            KEY created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}pos_sales (
            order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            device_id BIGINT UNSIGNED NOT NULL,
            cashier_user_id BIGINT UNSIGNED NOT NULL,
            client_uuid CHAR(36) NULL UNIQUE,
            created_at DATETIME(3) NOT NULL,
            KEY device_day (device_id, created_at),
            KEY cashier (cashier_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}pos_returns (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_uuid CHAR(36) NOT NULL UNIQUE,
            refund_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(3) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}refund_items (
            refund_id BIGINT UNSIGNED NOT NULL,
            order_item_id BIGINT UNSIGNED NOT NULL,
            qty_pieces INT UNSIGNED NOT NULL,
            amount_kobo BIGINT NOT NULL,
            PRIMARY KEY (refund_id, order_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
};
