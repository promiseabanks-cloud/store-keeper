<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core\Idempotency;

use StoreKeeper\Commerce\Core\Exception\StoreKeeperException;

/**
 * Same idempotency key with a different body (§18).
 */
final class DuplicateRequest extends StoreKeeperException
{
    public function errorCode(): string
    {
        return 'idempotency_conflict';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
