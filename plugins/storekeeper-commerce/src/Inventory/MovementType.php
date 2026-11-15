<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Inventory;

/**
 * Every ledger row type and its effect on on_hand/reserved (§9.2).
 */
final class MovementType
{
    public const OPENING = 'opening';
    public const RECEIVE = 'receive';
    public const RESERVE = 'reserve';
    public const RELEASE = 'release';
    public const COMMIT = 'commit';
    public const COUNTER_SALE = 'counter_sale';
    public const RETURN = 'return';
    public const TRANSFER_OUT = 'transfer_out';
    public const TRANSFER_IN = 'transfer_in';
    public const ADJUST = 'adjust';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::OPENING,
            self::RECEIVE,
            self::RESERVE,
            self::RELEASE,
            self::COMMIT,
            self::COUNTER_SALE,
            self::RETURN,
            self::TRANSFER_OUT,
            self::TRANSFER_IN,
            self::ADJUST,
        ];
    }
}
