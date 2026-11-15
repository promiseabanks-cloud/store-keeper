<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Pricing;

/**
 * Which rule produced a price (§8.4 order_items.price_source).
 */
final class PriceSource
{
    public const CUSTOMER = 'customer';
    public const GROUP = 'group';
    public const GROUP_TIER = 'group_tier';
    public const BASE = 'base';
    public const BASE_TIER = 'base_tier';
    public const MANUAL = 'manual';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::CUSTOMER, self::GROUP, self::GROUP_TIER, self::BASE, self::BASE_TIER, self::MANUAL];
    }
}
