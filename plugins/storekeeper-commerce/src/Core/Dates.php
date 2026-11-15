<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core;

/**
 * Time and date display helpers; stored times stay UTC DATETIME(3) (§7.3).
 */
final class Dates
{
    public static function utcNow(): string
    {
        return gmdate('Y-m-d H:i:s.v');
    }

    /** Add minutes to a UTC datetime string and return it in DATETIME(3). */
    public static function addMinutes(string $utc, int $minutes): string
    {
        $time = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));

        return $time->modify(sprintf('%+d minutes', $minutes))->format('Y-m-d H:i:s.v');
    }

    /** Parse a client-supplied ISO-8601 timestamp into DATETIME(3) UTC, or null. */
    public static function fromIso(?string $iso): ?string
    {
        if ($iso === null || trim($iso) === '') {
            return null;
        }

        try {
            $time = new \DateTimeImmutable($iso);
        } catch (\Exception) {
            return null;
        }

        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
