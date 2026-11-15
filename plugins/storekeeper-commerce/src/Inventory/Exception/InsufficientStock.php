<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Inventory\Exception;

use StoreKeeper\Commerce\Core\Exception\StoreKeeperException;

/**
 * A reservation or transfer asked for more than is available at a location (§9.4).
 */
final class InsufficientStock extends StoreKeeperException
{
    public function __construct(
        public readonly int $variantId,
        public readonly int $locationId,
        public readonly int $requested,
        public readonly int $available,
        public readonly ?int $orderId = null,
    ) {
        parent::__construct(sprintf(
            'Only %d of variant %d are available at location %d (requested %d).',
            $available,
            $variantId,
            $locationId,
            $requested
        ));
    }

    public function errorCode(): string
    {
        return 'insufficient_stock';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function context(): array
    {
        return [
            'variant_id' => $this->variantId,
            'location_id' => $this->locationId,
            'requested' => $this->requested,
            'available' => $this->available,
            'order_id' => $this->orderId,
        ];
    }
}
