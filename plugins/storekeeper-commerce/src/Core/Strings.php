<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core;

/**
 * Small string helpers shared by the engine (slugs, SKUs, order prefixes).
 */
final class Strings
{
    public static function slug(string $value, string $fallback = 'item'): string
    {
        $value = trim($value);
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $value));
        $slug = trim($slug, '-');

        return $slug === '' ? $fallback : substr($slug, 0, 191);
    }

    /** Suffix a slug until it is unique according to the callback. */
    public static function uniqueSlug(string $value, callable $exists, string $fallback = 'item'): string
    {
        $slug = self::slug($value, $fallback);
        $candidate = $slug;
        $counter = 2;

        while ($exists($candidate)) {
            $candidate = substr($slug, 0, 180) . '-' . $counter;
            $counter++;
        }

        return $candidate;
    }

    public static function initials(string $name, int $length = 3): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];
        $initials = '';

        foreach ($words as $word) {
            if ($word !== '') {
                $initials .= strtoupper(substr($word, 0, 1));
            }
            if (strlen($initials) >= $length) {
                break;
            }
        }

        if ($initials === '') {
            $initials = 'SK';
        }

        return strtoupper(substr($initials, 0, $length));
    }
}
