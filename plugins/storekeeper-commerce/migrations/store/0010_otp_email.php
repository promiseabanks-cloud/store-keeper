<?php

declare(strict_types=1);

/**
 * §8.2 `otp_codes`: sign-in and sign-up codes are emailed now, so the channel
 * enum has to allow it. WhatsApp and SMS stay for the adapters that Q-005 and
 * Q-006 will bring.
 */
return static function (string $prefix): array {
    return [
        "ALTER TABLE {p}otp_codes MODIFY COLUMN channel ENUM('email','whatsapp','sms') NOT NULL",
    ];
};
