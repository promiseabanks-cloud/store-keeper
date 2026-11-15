<?php

declare(strict_types=1);

/**
 * §8.6 Network/platform tables (the Owner's business): plans, applications,
 * tenants, subscriptions, platform invoices, usage counters, service requests
 * and the platform audit log.
 *
 * These use the network prefix, e.g. wp_storekeeper_plans.
 */
return static function (string $prefix): array {
    return [
        "CREATE TABLE IF NOT EXISTS {p}plans (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(32) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            monthly_kobo BIGINT NOT NULL,
            setup_fee_kobo BIGINT NOT NULL,
            annual_kobo BIGINT NULL,
            limits JSON NOT NULL,
            features JSON NOT NULL,
            paystack_plan_code VARCHAR(64) NULL,
            is_public TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME(3) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}applications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            business_name VARCHAR(191) NOT NULL,
            contact_name VARCHAR(191) NOT NULL,
            phone_e164 VARCHAR(20) NOT NULL,
            email VARCHAR(191) NULL,
            category VARCHAR(100) NULL,
            sells_to ENUM('wholesale','retail','both') NOT NULL,
            approx_products INT NULL,
            current_tools VARCHAR(255) NULL,
            instagram VARCHAR(100) NULL,
            message TEXT NULL,
            status ENUM('new','contacted','approved','rejected','converted') NOT NULL DEFAULT 'new',
            owner_notes TEXT NULL,
            created_at DATETIME(3) NOT NULL,
            updated_at DATETIME(3) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}tenants (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            blog_id BIGINT UNSIGNED NOT NULL UNIQUE,
            application_id BIGINT UNSIGNED NULL,
            business_name VARCHAR(191) NOT NULL,
            owner_user_id BIGINT UNSIGNED NOT NULL,
            plan_id BIGINT UNSIGNED NOT NULL,
            status ENUM('provisioning','trial','active','past_due','read_only','suspended','cancelled') NOT NULL,
            store_mode ENUM('retail','wholesale','both') NOT NULL DEFAULT 'both',
            primary_domain VARCHAR(191) NULL,
            trial_ends_at DATETIME(3) NULL,
            grace_ends_at DATETIME(3) NULL,
            created_at DATETIME(3) NOT NULL,
            updated_at DATETIME(3) NOT NULL,
            KEY status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}subscriptions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id BIGINT UNSIGNED NOT NULL,
            plan_id BIGINT UNSIGNED NOT NULL,
            provider ENUM('paystack','manual') NOT NULL DEFAULT 'paystack',
            provider_customer_code VARCHAR(64) NULL,
            provider_subscription_code VARCHAR(64) NULL,
            provider_email_token VARCHAR(64) NULL,
            status ENUM('active','non_renewing','attention','cancelled','completed') NOT NULL,
            current_period_start DATETIME(3) NULL,
            current_period_end DATETIME(3) NULL,
            next_payment_at DATETIME(3) NULL,
            created_at DATETIME(3) NOT NULL,
            updated_at DATETIME(3) NOT NULL,
            KEY tenant (tenant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}platform_invoices (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            tenant_id BIGINT UNSIGNED NOT NULL,
            kind ENUM('setup','subscription','addon','service','message_overage') NOT NULL,
            description VARCHAR(255) NOT NULL,
            amount_kobo BIGINT NOT NULL,
            status ENUM('draft','open','paid','void') NOT NULL,
            provider_reference VARCHAR(100) NULL UNIQUE,
            due_at DATETIME(3) NULL,
            paid_at DATETIME(3) NULL,
            created_at DATETIME(3) NOT NULL,
            KEY tenant (tenant_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}usage_counters (
            tenant_id BIGINT UNSIGNED NOT NULL,
            metric VARCHAR(32) NOT NULL,
            period CHAR(7) NOT NULL,
            value BIGINT NOT NULL,
            updated_at DATETIME(3) NOT NULL,
            PRIMARY KEY (tenant_id, metric, period)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}service_requests (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(191) NOT NULL,
            details TEXT NULL,
            status ENUM('open','in_progress','done','declined') NOT NULL DEFAULT 'open',
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(3) NOT NULL,
            updated_at DATETIME(3) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}platform_audit_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            actor_id BIGINT UNSIGNED NOT NULL,
            tenant_id BIGINT UNSIGNED NULL,
            action VARCHAR(64) NOT NULL,
            details JSON NULL,
            ip VARCHAR(45) NULL,
            created_at DATETIME(3) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
};
