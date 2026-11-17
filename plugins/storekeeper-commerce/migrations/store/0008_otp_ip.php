<?php

declare(strict_types=1);

/**
 * §8.2 `otp_codes`: one-time sign-in codes are limited per phone and per IP
 * address, so the row records which address asked for it.
 */
return static function (string $prefix): array {
    return [
        "ALTER TABLE {p}otp_codes ADD COLUMN ip VARCHAR(45) NULL AFTER phone_e164",
        "ALTER TABLE {p}otp_codes ADD KEY ip_created (ip, created_at)",
    ];
};
