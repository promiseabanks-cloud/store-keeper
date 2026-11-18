<?php

declare(strict_types=1);

namespace StoreKeeper\Platform\Plans;

use StoreKeeper\Commerce\Core\Exception\StoreKeeperException;

/**
 * Raised when a hard limit would be exceeded. The console shows an upgrade path
 * instead of an opaque failure (§17.3).
 */
final class PlanLimit extends StoreKeeperException
{
    public function __construct(
        public readonly string $metric,
        public readonly int $limit,
        public readonly int $attempted,
    ) {
        parent::__construct(sprintf(
            'The %s limit for this plan is %d (this change would make %d).',
            str_replace('_', ' ', $metric),
            $limit,
            $attempted
        ));
    }

    public function errorCode(): string
    {
        return 'plan_limit_reached';
    }

    public function httpStatus(): int
    {
        return 402;
    }

    public function context(): array
    {
        return ['metric' => $this->metric, 'limit' => $this->limit, 'attempted' => $this->attempted];
    }
}
