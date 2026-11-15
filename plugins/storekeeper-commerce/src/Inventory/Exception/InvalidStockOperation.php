<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Inventory\Exception;

use StoreKeeper\Commerce\Core\Exception\StoreKeeperException;

/**
 * A stock operation that would corrupt the ledger invariants (§9.2).
 */
final class InvalidStockOperation extends StoreKeeperException
{
    public function errorCode(): string
    {
        return 'invalid_stock_operation';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
