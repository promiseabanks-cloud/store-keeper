<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Storefront;

/**
 * Guest-facing fragment cache (§20). Group prices never leak between visitors
 * because the cache key carries the price list, and every write that changes what
 * the shop shows bumps a version number instead of hunting keys to delete.
 */
final class Cache
{
    private const VERSION_OPTION = 'storekeeper_storefront_cache_version';

    public const TTL = 300;

    public static function version(): int
    {
        return max(1, (int) get_option(self::VERSION_OPTION, 1));
    }

    public static function flush(): void
    {
        update_option(self::VERSION_OPTION, self::version() + 1, false);
    }

    /**
     * @template T of array<mixed>
     * @param callable():T $build
     * @return T
     */
    public static function remember(string $key, callable $build, int $ttl = self::TTL): array
    {
        if (!function_exists('get_transient')) {
            return $build();
        }

        $key = sprintf('skf_%d_%d_%s', (int) get_current_blog_id(), self::version(), md5($key));
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }

        $value = $build();
        set_transient($key, $value, $ttl);

        return $value;
    }
}
