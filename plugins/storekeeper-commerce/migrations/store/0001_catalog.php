<?php

declare(strict_types=1);

/**
 * §8.1 Catalog: categories, products, options, option values, variant grid,
 * variant↔option mapping, option-scoped media (R4), and pack units.
 */
return static function (string $prefix): array {
    return [
        "CREATE TABLE IF NOT EXISTS {p}categories (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            parent_id BIGINT UNSIGNED NULL,
            name VARCHAR(191) NOT NULL,
            slug VARCHAR(191) NOT NULL UNIQUE,
            image_id BIGINT UNSIGNED NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME(3) NOT NULL,
            updated_at DATETIME(3) NOT NULL,
            KEY parent (parent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}products (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(191) NOT NULL UNIQUE,
            description LONGTEXT NULL,
            short_description TEXT NULL,
            category_id BIGINT UNSIGNED NULL,
            status ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
            visibility ENUM('all','wholesale_only','retail_only','hidden') NOT NULL DEFAULT 'all',
            base_unit_label VARCHAR(32) NOT NULL DEFAULT 'piece',
            track_stock TINYINT(1) NOT NULL DEFAULT 1,
            allow_backorder TINYINT(1) NOT NULL DEFAULT 0,
            tags VARCHAR(500) NULL,
            seo_title VARCHAR(255) NULL,
            seo_description VARCHAR(500) NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME(3) NOT NULL,
            updated_at DATETIME(3) NOT NULL,
            KEY category (category_id),
            KEY status (status),
            FULLTEXT KEY search (name, tags, short_description)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}options (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(100) NOT NULL UNIQUE,
            display ENUM('color','image','label','dropdown') NOT NULL DEFAULT 'label',
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME(3) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}option_values (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            option_id BIGINT UNSIGNED NOT NULL,
            value VARCHAR(100) NOT NULL,
            slug VARCHAR(100) NOT NULL,
            color_hex CHAR(7) NULL,
            image_id BIGINT UNSIGNED NULL,
            sort_order INT NOT NULL DEFAULT 0,
            UNIQUE KEY option_slug (option_id, slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}product_options (
            product_id BIGINT UNSIGNED NOT NULL,
            option_id BIGINT UNSIGNED NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_media_option TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (product_id, option_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}variants (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            product_id BIGINT UNSIGNED NOT NULL,
            sku VARCHAR(64) NOT NULL UNIQUE,
            barcode VARCHAR(64) NULL,
            title VARCHAR(255) NOT NULL,
            weight_grams INT UNSIGNED NULL,
            cost_kobo BIGINT NULL,
            low_stock_threshold INT NULL,
            status ENUM('active','archived') NOT NULL DEFAULT 'active',
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME(3) NOT NULL,
            updated_at DATETIME(3) NOT NULL,
            KEY product (product_id),
            KEY barcode (barcode)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}variant_option_values (
            variant_id BIGINT UNSIGNED NOT NULL,
            option_value_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (variant_id, option_value_id),
            KEY value (option_value_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}product_media (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT UNSIGNED NOT NULL,
            option_value_id BIGINT UNSIGNED NULL,
            attachment_id BIGINT UNSIGNED NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_swatch_source TINYINT(1) NOT NULL DEFAULT 0,
            KEY product_value (product_id, option_value_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}pack_units (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT UNSIGNED NULL,
            name VARCHAR(50) NOT NULL,
            pieces INT UNSIGNED NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            KEY product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
};
