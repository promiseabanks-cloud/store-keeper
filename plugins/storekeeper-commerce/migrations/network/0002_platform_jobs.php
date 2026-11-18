<?php

declare(strict_types=1);

/**
 * §17.2: every provisioning step is recorded so the Owner can see where a
 * tenant setup stopped. Network-scoped, like the rest of the platform tables.
 */
return static function (string $prefix): array {
    return [
        "CREATE TABLE IF NOT EXISTS {p}platform_jobs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tenant_id BIGINT UNSIGNED NULL,
            application_id BIGINT UNSIGNED NULL,
            step VARCHAR(64) NOT NULL,
            status ENUM('started','done','failed','skipped') NOT NULL,
            message VARCHAR(255) NULL,
            created_at DATETIME(3) NOT NULL,
            KEY tenant (tenant_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
};
