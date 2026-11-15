<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core;

use RuntimeException;

/**
 * Secret encryption at rest (§19.4).
 *
 * Gateway secret keys and API tokens are stored with
 * sodium_crypto_secretbox, keyed from the WSP_ENCRYPTION_KEY constant in
 * wp-config.php. The key never lives in the database, so a stolen dump alone
 * cannot reveal the merchant's payment credentials.
 */
final class Crypto
{
    private const CIPHER = 'secretbox';

    /** @return string base64(nonce . ciphertext), safe for a text column */
    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, self::key());

        return base64_encode(self::CIPHER . ':' . $nonce . $cipher);
    }

    public static function decrypt(string $stored): string
    {
        if ($stored === '') {
            return '';
        }

        $raw = base64_decode($stored, true);
        if ($raw === false) {
            return '';
        }

        [$cipherName, $payload] = array_pad(explode(':', $raw, 2), 2, '');
        if ($cipherName !== self::CIPHER || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }

        $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, self::key());

        return $plain === false ? '' : $plain;
    }

    /** Whether the site can encrypt secrets at all. */
    public static function available(): bool
    {
        return defined(Brand::ENCRYPTION_KEY_CONSTANT)
            && strlen((string) constant(Brand::ENCRYPTION_KEY_CONSTANT)) >= 32
            && function_exists('sodium_crypto_secretbox');
    }

    private static function key(): string
    {
        if (!self::available()) {
            throw new RuntimeException(
                'Set the WSP_ENCRYPTION_KEY constant (32+ characters) in wp-config.php to store payment secrets.'
            );
        }

        return hash('sha256', (string) constant(Brand::ENCRYPTION_KEY_CONSTANT), true);
    }
}
