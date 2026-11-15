<?php

declare(strict_types=1);

/**
 * §8.2 Pricing and customers: price lists with quantity tiers, customer groups
 * (R1/R5), customers (not WordPress users, §7.4), special prices, addresses,
 * customer sessions and OTP codes.
 */
return static function (string $prefix): array {
    return [
        "CREATE TABLE IF NOT EXISTS {p}price_lists (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'NGN',
            is_base TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME(3) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}prices (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            price_list_id BIGINT UNSIGNED NOT NULL,
            variant_id BIGINT UNSIGNED NOT NULL,
            pack_unit_id BIGINT UNSIGNED NULL,
            min_qty INT UNSIGNED NOT NULL DEFAULT 1,
            amount_kobo BIGINT NOT NULL,
            updated_at DATETIME(3) NOT NULL,
            UNIQUE KEY tier (price_list_id, variant_id, pack_unit_id, min_qty),
            KEY variant (variant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}customer_groups (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            type ENUM('retail','wholesale','custom') NOT NULL,
            price_list_id BIGINT UNSIGNED NOT NULL,
            approval ENUM('auto','manual') NOT NULL DEFAULT 'auto',
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            is_self_registerable TINYINT(1) NOT NULL DEFAULT 1,
            min_order_value_kobo BIGINT NULL,
            ignore_item_minimums TINYINT(1) NOT NULL DEFAULT 1,
            can_pay_on_delivery TINYINT(1) NOT NULL DEFAULT 0,
            credit_limit_kobo BIGINT NULL,
            created_at DATETIME(3) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}customers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            phone_e164 VARCHAR(20) NOT NULL UNIQUE,
            name VARCHAR(191) NOT NULL,
            business_name VARCHAR(191) NULL,
            email VARCHAR(191) NULL,
            group_id BIGINT UNSIGNED NOT NULL,
            requested_group_id BIGINT UNSIGNED NULL,
            approval_status ENUM('approved','pending','rejected') NOT NULL DEFAULT 'approved',
            source ENUM('storefront','staff','pos','import') NOT NULL,
            whatsapp_opt_in TINYINT(1) NOT NULL DEFAULT 1,
            marketing_opt_in TINYINT(1) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            total_spent_kobo BIGINT NOT NULL DEFAULT 0,
            order_count INT NOT NULL DEFAULT 0,
            last_order_at DATETIME(3) NULL,
            created_at DATETIME(3) NOT NULL,
            updated_at DATETIME(3) NOT NULL,
            KEY grp (group_id),
            KEY name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}customer_prices (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id BIGINT UNSIGNED NOT NULL,
            variant_id BIGINT UNSIGNED NOT NULL,
            pack_unit_id BIGINT UNSIGNED NULL,
            amount_kobo BIGINT NOT NULL,
            valid_from DATETIME(3) NULL,
            valid_to DATETIME(3) NULL,
            UNIQUE KEY one (customer_id, variant_id, pack_unit_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}addresses (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id BIGINT UNSIGNED NOT NULL,
            label VARCHAR(50) NULL,
            recipient_name VARCHAR(191) NOT NULL,
            phone_e164 VARCHAR(20) NOT NULL,
            line1 VARCHAR(255) NOT NULL,
            line2 VARCHAR(255) NULL,
            landmark VARCHAR(255) NULL,
            city VARCHAR(100) NULL,
            lga VARCHAR(100) NOT NULL,
            state VARCHAR(50) NOT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            KEY customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}customer_sessions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME(3) NOT NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME(3) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}otp_codes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            phone_e164 VARCHAR(20) NOT NULL,
            code_hash CHAR(64) NOT NULL,
            channel ENUM('whatsapp','sms') NOT NULL,
            purpose ENUM('login','register','staff_login') NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            expires_at DATETIME(3) NOT NULL,
            consumed_at DATETIME(3) NULL,
            created_at DATETIME(3) NOT NULL,
            KEY phone (phone_e164, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
};
