<?php

declare(strict_types=1);

/**
 * §8.3 / §22.5 Carts: a server-side cart owned by a browser session, so a price
 * is always resolved again on read and nothing about money lives in a cookie.
 *
 * `pack_unit_id` is NOT NULL DEFAULT 0 because MySQL treats NULLs as distinct in
 * a UNIQUE key; 0 means "single piece" and the service converts it to null.
 */
return static function (string $prefix): array {
    return [
        "CREATE TABLE IF NOT EXISTS {p}carts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            token_hash CHAR(64) NOT NULL UNIQUE,
            customer_id BIGINT UNSIGNED NULL,
            currency CHAR(3) NOT NULL DEFAULT 'NGN',
            status ENUM('active','converted','abandoned') NOT NULL DEFAULT 'active',
            converted_order_id BIGINT UNSIGNED NULL,
            expires_at DATETIME(3) NOT NULL,
            created_at DATETIME(3) NOT NULL,
            updated_at DATETIME(3) NOT NULL,
            KEY customer (customer_id),
            KEY expiry (status, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}cart_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cart_id BIGINT UNSIGNED NOT NULL,
            variant_id BIGINT UNSIGNED NOT NULL,
            pack_unit_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            qty INT UNSIGNED NOT NULL,
            added_at DATETIME(3) NOT NULL,
            UNIQUE KEY one_line (cart_id, variant_id, pack_unit_id),
            KEY cart (cart_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
};
