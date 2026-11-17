<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Orders;

use StoreKeeper\Commerce\Orders\Exception\InvalidTransition;

/**
 * A parcel's own state machine (§14.1), separate from the order's three
 * statuses. A paid order can still be in several parcels, each at a different
 * point.
 */
final class ShipmentStatus
{
    public const PENDING = 'pending';
    public const PACKED = 'packed';
    public const READY_FOR_PICKUP = 'ready_for_pickup';
    public const DISPATCHED = 'dispatched';
    public const DELIVERED = 'delivered';
    public const FAILED_DELIVERY = 'failed_delivery';
    public const RETURNED = 'returned';
    public const CANCELLED = 'cancelled';

    /** @var array<string,list<string>> */
    private const NEXT = [
        self::PENDING => [self::PACKED, self::CANCELLED],
        self::PACKED => [self::DISPATCHED, self::READY_FOR_PICKUP, self::CANCELLED],
        self::READY_FOR_PICKUP => [self::DELIVERED, self::FAILED_DELIVERY, self::RETURNED],
        self::DISPATCHED => [self::DELIVERED, self::FAILED_DELIVERY],
        self::FAILED_DELIVERY => [self::DISPATCHED, self::RETURNED],
        self::DELIVERED => [],
        self::RETURNED => [],
        self::CANCELLED => [],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::NEXT);
    }

    /** @return list<string> */
    public static function next(string $from): array
    {
        return self::NEXT[$from] ?? [];
    }

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::next($from), true);
    }

    public static function assert(string $from, string $to): void
    {
        if (!in_array($from, self::all(), true)) {
            throw new InvalidTransition($from, $to, 'shipment');
        }

        if (!self::canMove($from, $to)) {
            throw new InvalidTransition($from, $to, 'shipment');
        }
    }

    /** Parcels that still hold goods for the order. */
    public static function isOpen(string $status): bool
    {
        return in_array($status, [self::PENDING, self::PACKED, self::READY_FOR_PICKUP, self::DISPATCHED, self::FAILED_DELIVERY], true);
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::PENDING => 'Not packed yet',
            self::PACKED => 'Packed',
            self::READY_FOR_PICKUP => 'Ready for pickup',
            self::DISPATCHED => 'On the way',
            self::DELIVERED => 'Delivered',
            self::FAILED_DELIVERY => 'Delivery failed',
            self::RETURNED => 'Returned',
            self::CANCELLED => 'Cancelled',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}
