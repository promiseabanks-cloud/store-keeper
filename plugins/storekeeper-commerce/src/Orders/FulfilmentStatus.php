<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Orders;

/**
 * Fulfilment status is computed from shipment items and per-line fulfilled
 * quantities (§12.3).
 */
final class FulfilmentStatus
{
    public const UNFULFILLED = 'unfulfilled';
    public const PARTIALLY_FULFILLED = 'partially_fulfilled';
    public const FULFILLED = 'fulfilled';
    public const RETURNED = 'returned';

    /**
     * @param list<array{qty_pieces:int,qty_fulfilled_pieces:int}> $items
     */
    public static function derive(array $items, int $returnedPieces = 0): string
    {
        $total = 0;
        $fulfilled = 0;

        foreach ($items as $item) {
            $total += (int) $item['qty_pieces'];
            $fulfilled += (int) $item['qty_fulfilled_pieces'];
        }

        if ($total === 0) {
            return self::UNFULFILLED;
        }

        if ($returnedPieces >= $total) {
            return self::RETURNED;
        }

        if ($fulfilled >= $total) {
            return self::FULFILLED;
        }

        return $fulfilled > 0 ? self::PARTIALLY_FULFILLED : self::UNFULFILLED;
    }
}
