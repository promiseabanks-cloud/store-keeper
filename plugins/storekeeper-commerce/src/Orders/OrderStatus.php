<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Orders;

use StoreKeeper\Commerce\Orders\Exception\InvalidTransition;

/**
 * Overall order status machine (§12.1).
 *
 * draft ──► open ──► completed
 *   │        │
 *   └────────┴──────► cancelled
 *
 * completed and cancelled are terminal: returns and refunds do not reopen an order.
 */
final class OrderStatus
{
    public const DRAFT = 'draft';
    public const OPEN = 'open';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    private const TRANSITIONS = [
        self::DRAFT => [self::OPEN, self::CANCELLED],
        self::OPEN => [self::COMPLETED, self::CANCELLED],
        self::COMPLETED => [],
        self::CANCELLED => [],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return [self::DRAFT, self::OPEN, self::COMPLETED, self::CANCELLED];
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function assertTransition(string $from, string $to): void
    {
        if (!self::canTransition($from, $to)) {
            throw new InvalidTransition($from, $to);
        }
    }

    public static function isTerminal(string $status): bool
    {
        return self::TRANSITIONS[$status] === [];
    }
}
