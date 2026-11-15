<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core;

use InvalidArgumentException;

/**
 * RFC 4122 version 4 UUIDs, used as the public identifier of every entity (§8).
 */
final class Uuid
{
    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public static function isValid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }

    /** Random 32-character token for public tracking links (§8.4). */
    public static function publicToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function assertValid(string $value): string
    {
        $value = trim($value);
        if (!self::isValid($value)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a UUID.', $value));
        }

        return strtolower($value);
    }
}
