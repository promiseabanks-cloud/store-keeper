<?php

declare(strict_types=1);

/**
 * §8.3 Inventory: locations, the derived stock_levels cache, the append-only
 * stock_movements ledger (§9), receipts, transfers and counts.
 */
return static function (string $prefix): array {
    return [
        "CREATE TABLE IF NOT EXISTS {p}locations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            type ENUM('shop','warehouse','online_pool') NOT NULL,
            sellable_online TINYINT(1) NOT NULL DEFAULT 1,
            online_priority INT NOT NULL DEFAULT 0,
            address VARCHAR(500) NULL,
            is_pickup_point TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME(3) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}stock_levels (
            variant_id BIGINT UNSIGNED NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            on_hand INT NOT NULL DEFAULT 0,
            reserved INT NOT NULL DEFAULT 0,
            last_movement_id BIGINT UNSIGNED NULL,
            updated_at DATETIME(3) NOT NULL,
            PRIMARY KEY (variant_id, location_id),
            KEY location (location_id),
            CONSTRAINT reserved_nonneg CHECK (reserved >= 0)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}stock_movements (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            variant_id BIGINT UNSIGNED NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            type ENUM('receive','reserve','release','commit','counter_sale','return','transfer_out','transfer_in','adjust','opening') NOT NULL,
            on_hand_delta INT NOT NULL DEFAULT 0,
            reserved_delta INT NOT NULL DEFAULT 0,
            reason VARCHAR(64) NULL,
            order_id BIGINT UNSIGNED NULL,
            order_item_id BIGINT UNSIGNED NULL,
            transfer_id BIGINT UNSIGNED NULL,
            count_id BIGINT UNSIGNED NULL,
            receipt_id BIGINT UNSIGNED NULL,
            actor_type ENUM('staff','customer','system','device') NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            device_id BIGINT UNSIGNED NULL,
            client_created_at DATETIME(3) NULL,
            note VARCHAR(255) NULL,
            created_at DATETIME(3) NOT NULL,
            KEY variant_location (variant_id, location_id, id),
            KEY order_ref (order_id),
            KEY created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}stock_receipts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            location_id BIGINT UNSIGNED NOT NULL,
            supplier VARCHAR(191) NULL,
            reference VARCHAR(100) NULL,
            total_cost_kobo BIGINT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(3) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}stock_transfers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            from_location_id BIGINT UNSIGNED NOT NULL,
            to_location_id BIGINT UNSIGNED NOT NULL,
            status ENUM('draft','sent','received','cancelled') NOT NULL DEFAULT 'draft',
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(3) NOT NULL,
            sent_at DATETIME(3) NULL,
            received_at DATETIME(3) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}stock_transfer_lines (
            transfer_id BIGINT UNSIGNED NOT NULL,
            variant_id BIGINT UNSIGNED NOT NULL,
            qty INT UNSIGNED NOT NULL,
            qty_received INT UNSIGNED NULL,
            PRIMARY KEY (transfer_id, variant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}stock_counts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            location_id BIGINT UNSIGNED NOT NULL,
            scope VARCHAR(255) NULL,
            status ENUM('open','submitted','applied','cancelled') NOT NULL DEFAULT 'open',
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(3) NOT NULL,
            applied_at DATETIME(3) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}stock_count_lines (
            count_id BIGINT UNSIGNED NOT NULL,
            variant_id BIGINT UNSIGNED NOT NULL,
            expected_qty INT NOT NULL,
            counted_qty INT NOT NULL,
            counted_by BIGINT UNSIGNED NOT NULL,
            counted_at DATETIME(3) NOT NULL,
            PRIMARY KEY (count_id, variant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
};
