<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Pricing\Exception;

use StoreKeeper\Commerce\Core\Exception\StoreKeeperException;

/**
 * No price exists for the variant/pack in any applicable list (§10.1 step 4).
 */
final class PriceNotFound extends StoreKeeperException
{
    public function __construct(
        public readonly int $variantId,
        public readonly ?int $packUnitId = null,
        public readonly ?int $priceListId = null,
    ) {
        parent::__construct(sprintf(
            'No price is set for variant %d%s.',
            $variantId,
            $priceListId === null ? '' : sprintf(' in price list %d', $priceListId)
        ));
    }

    public function errorCode(): string
    {
        return 'price_not_found';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function context(): array
    {
        return [
            'variant_id' => $this->variantId,
            'pack_unit_id' => $this->packUnitId,
            'price_list_id' => $this->priceListId,
        ];
    }
}
