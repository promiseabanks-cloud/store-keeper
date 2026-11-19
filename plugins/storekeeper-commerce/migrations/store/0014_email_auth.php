<?php

declare(strict_types=1);

/**
 * §7.4 / §11.1 Email + password + Google sign-in for storefront customers.
 *
 * Customers stop signing in with a phone number and a one-time code: they sign
 * up with an email address and a password, or with Google. So the customer row
 * grows a password hash and a Google subject id, and the phone number — kept
 * only for delivery now — becomes optional.
 */
return static function (string $prefix): array {
    return [
        "ALTER TABLE {p}customers
            ADD COLUMN password_hash VARCHAR(255) NULL AFTER email,
            ADD COLUMN google_sub VARCHAR(191) NULL AFTER password_hash",

        // The phone number is no longer how a customer signs in, so a shopper
        // who registers by email need not give one until checkout.
        "ALTER TABLE {p}customers MODIFY COLUMN phone_e164 VARCHAR(20) NULL",

        // A Google account maps to exactly one customer. Email uniqueness is
        // enforced in the service layer, not here, so existing rows that share a
        // seeded address are never rejected by the migration.
        "ALTER TABLE {p}customers ADD UNIQUE KEY google_sub (google_sub)",

        // Sign-in codes may still be emailed for other flows; add the 'login'
        // purpose is already present. Nothing else to change on otp_codes.
    ];
};
