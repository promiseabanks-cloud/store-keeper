<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Storefront;

use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Core\Money;

/**
 * Storefront price text. `Money::format()` always prints two decimals, which suits
 * invoices but reads badly on a product card, so shop prices drop the ".00".
 */
final class Format
{
    public static function price(int $kobo, string $currency = Brand::CURRENCY): string
    {
        $absolute = abs($kobo);
        $decimals = $absolute % 100 === 0 ? 0 : 2;
        $body = number_format($absolute / 100, $decimals, '.', ',');
        $symbol = match ($currency) {
            'NGN' => '₦',
            'USD' => '$',
            'GBP' => '£',
            'EUR' => '€',
            default => $currency . ' ',
        };

        return ($kobo < 0 ? '-' : '') . $symbol . $body;
    }

    public static function money(int $kobo, string $currency = Brand::CURRENCY): string
    {
        return (new Money($kobo, $currency))->format();
    }

    /** "2 in stock" / "Out of stock", never an exact count for large amounts. */
    public static function stock(int $available): string
    {
        if ($available <= 0) {
            return 'Out of stock';
        }

        return $available <= 5 ? sprintf('%d left', $available) : 'In stock';
    }
}
