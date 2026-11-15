<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Pricing;

use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Core\Money;

/**
 * One resolved price: a unit amount in the requested pack unit, the rule that
 * matched, and the quantity tier that applied (§10.1).
 */
final class ResolvedPrice
{
    public function __construct(
        public readonly int $variantId,
        public readonly ?int $packUnitId,
        public readonly int $qty,
        public readonly Money $unitAmount,
        public readonly string $source,
        public readonly int $minQty,
        public readonly int $packPieces = 1,
        public readonly ?int $priceListId = null,
        public readonly ?int $customerId = null,
    ) {
    }

    /** Total for the requested quantity of this pack unit. */
    public function lineTotal(): Money
    {
        return $this->unitAmount->multiply($this->qty);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'variant_id' => $this->variantId,
            'pack_unit_id' => $this->packUnitId,
            'qty' => $this->qty,
            'unit_amount_kobo' => $this->unitAmount->minor(),
            'currency' => $this->unitAmount->currency(),
            'source' => $this->source,
            'min_qty' => $this->minQty,
            'pack_pieces' => $this->packPieces,
            'price_list_id' => $this->priceListId,
            'customer_id' => $this->customerId,
            'line_total_kobo' => $this->lineTotal()->minor(),
        ];
    }

    public static function currency(): string
    {
        return Brand::CURRENCY;
    }
}
