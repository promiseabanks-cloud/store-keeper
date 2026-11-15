<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core\Idempotency;

/**
 * Outcome of an idempotent write.
 */
final class IdempotencyResult
{
    /** @param array<string,mixed> $response */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $response,
        public readonly bool $replayed,
    ) {
    }
}
