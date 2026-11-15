<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core\Exception;

use RuntimeException;

/**
 * Base class for every domain error the REST layer maps to a stable error code (§7.3).
 */
class StoreKeeperException extends RuntimeException
{
    /** Stable, machine-readable error code used by the API (§18). */
    public function errorCode(): string
    {
        return 'storekeeper_error';
    }

    /** HTTP status the REST layer should return for this error. */
    public function httpStatus(): int
    {
        return 400;
    }

    /** @return array<string,mixed> */
    public function context(): array
    {
        return [];
    }
}
