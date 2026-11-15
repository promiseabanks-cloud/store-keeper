<?php

declare(strict_types=1);

/**
 * §8.5 Devices, sync, messaging and system tables: POS devices and batches,
 * idempotency keys, message templates and log, alerts, audit log, imports,
 * and gap-tolerant sequences.
 */
return static function (string $prefix): array {
    return [
        "CREATE TABLE IF NOT EXISTS {p}devices (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            status ENUM('active','revoked') NOT NULL DEFAULT 'active',
            app_version VARCHAR(20) NULL,
            last_sync_at DATETIME(3) NULL,
            last_seen_at DATETIME(3) NULL,
            registered_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(3) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}sync_batches (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            device_id BIGINT UNSIGNED NOT NULL,
            batch_uuid CHAR(36) NOT NULL UNIQUE,
            item_count INT UNSIGNED NOT NULL,
            result JSON NOT NULL,
            received_at DATETIME(3) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}idempotency_keys (
            idem_key VARCHAR(64) NOT NULL,
            scope VARCHAR(64) NOT NULL,
            actor_key VARCHAR(64) NOT NULL,
            request_hash CHAR(64) NOT NULL,
            status_code SMALLINT NOT NULL,
            response JSON NOT NULL,
            created_at DATETIME(3) NOT NULL,
            PRIMARY KEY (scope, actor_key, idem_key),
            KEY created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}message_templates (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event VARCHAR(64) NOT NULL,
            channel ENUM('whatsapp_api','sms','email','tap_to_send') NOT NULL,
            provider_template_name VARCHAR(100) NULL,
            language VARCHAR(10) NOT NULL DEFAULT 'en',
            body TEXT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY one (event, channel, language)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}message_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event VARCHAR(64) NOT NULL,
            channel VARCHAR(20) NOT NULL,
            category ENUM('utility','marketing','authentication','service','none') NOT NULL,
            recipient VARCHAR(191) NOT NULL,
            order_id BIGINT UNSIGNED NULL,
            customer_id BIGINT UNSIGNED NULL,
            status ENUM('queued','sent','delivered','read','failed') NOT NULL,
            provider VARCHAR(50) NULL,
            provider_message_id VARCHAR(191) NULL,
            cost_micro_usd INT NULL,
            cost_kobo BIGINT NULL,
            error VARCHAR(255) NULL,
            created_at DATETIME(3) NOT NULL,
            updated_at DATETIME(3) NOT NULL,
            KEY created (created_at),
            KEY order_ref (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}alerts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            type ENUM('oversell','low_stock','ledger_mismatch','device_stale','payment_mismatch','webhook_failure','import_errors') NOT NULL,
            severity ENUM('info','warning','critical') NOT NULL,
            subject_type VARCHAR(32) NULL,
            subject_id BIGINT UNSIGNED NULL,
            payload JSON NOT NULL,
            status ENUM('open','acknowledged','resolved') NOT NULL DEFAULT 'open',
            resolved_by BIGINT UNSIGNED NULL,
            resolution VARCHAR(255) NULL,
            created_at DATETIME(3) NOT NULL,
            resolved_at DATETIME(3) NULL,
            KEY open_alerts (status, type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}audit_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            actor_type ENUM('staff','owner_impersonation','system','device','customer') NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            action VARCHAR(64) NOT NULL,
            entity_type VARCHAR(32) NOT NULL,
            entity_id BIGINT UNSIGNED NULL,
            before_data JSON NULL,
            after_data JSON NULL,
            ip VARCHAR(45) NULL,
            created_at DATETIME(3) NOT NULL,
            KEY entity (entity_type, entity_id),
            KEY created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}imports (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            kind ENUM('products','stock','prices','customers','bumpa') NOT NULL,
            attachment_id BIGINT UNSIGNED NOT NULL,
            mapping JSON NULL,
            status ENUM('uploaded','mapped','validating','ready','importing','done','failed') NOT NULL,
            rows_total INT NULL,
            rows_ok INT NULL,
            rows_failed INT NULL,
            errors JSON NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(3) NOT NULL,
            updated_at DATETIME(3) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}sequences (
            name VARCHAR(32) PRIMARY KEY,
            value BIGINT UNSIGNED NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
};
