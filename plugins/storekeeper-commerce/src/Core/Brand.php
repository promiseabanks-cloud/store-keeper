<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core;

/**
 * Single source of truth for every user-facing name and every prefix (§7.2).
 *
 * Renaming the product is a find/replace of the PHP namespace plus an edit here.
 * No other file may hard-code the product name, table prefix, REST namespace,
 * option prefix, capability prefix or text domain.
 */
final class Brand
{
    /** Working product name; final name is TBD-OWNER (§24 Q-002). */
    public const NAME = 'Store Keeper';

    /** Short codename used in logs and job names. */
    public const CODENAME = 'storekeeper';

    /** Table prefix per store, appended to $wpdb->prefix: {p} = prefix + TABLE_PREFIX. */
    public const TABLE_PREFIX = 'storekeeper_';

    /** REST API namespace. */
    public const REST_NAMESPACE = 'storekeeper/v1';

    /** Hook/action prefix, e.g. storekeeper/order/created. */
    public const HOOK_PREFIX = 'storekeeper/';

    /** WordPress option prefix. */
    public const OPTION_PREFIX = 'storekeeper_';

    /** Capability prefix, e.g. storekeeper_edit_prices. */
    public const CAPABILITY_PREFIX = 'storekeeper_';

    /** Text domain for every translatable string. */
    public const TEXT_DOMAIN = 'storekeeper';

    /** Only currency in v1 (§3.2); every money value still carries its own code. */
    public const CURRENCY = 'NGN';

    /** Default store timezone (§7.3). */
    public const TIMEZONE = 'Africa/Lagos';

    /** Option name holding the applied schema version per site. */
    public const SCHEMA_VERSION_OPTION = self::OPTION_PREFIX . 'schema_version';

    /** WordPress encryption key constant name for secrets at rest (§19.4). */
    public const ENCRYPTION_KEY_CONSTANT = 'WSP_ENCRYPTION_KEY';

    public static function hook(string $name): string
    {
        return self::HOOK_PREFIX . ltrim($name, '/');
    }

    public static function capability(string $name): string
    {
        return self::CAPABILITY_PREFIX . ltrim($name, '_');
    }
}
