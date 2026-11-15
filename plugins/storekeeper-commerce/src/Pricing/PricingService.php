<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Pricing;

use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Audit\AuditLogger;
use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Core\Money;
use StoreKeeper\Commerce\Pricing\Exception\PriceNotFound;

/**
 * Price resolution (§10.1): customer-specific price → group list best tier
 * (with the ignore-minimums fallback that solves R1) → base list tier →
 * derived pack price → PriceNotFound.
 */
final class PricingService
{
    public function __construct(
        private readonly PricingRepository $prices,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly bool $derivePackPrices = true,
    ) {
    }

    /**
     * @param array<string,mixed>|null $customer row from {p}customers, or null for a guest
     */
    public function resolve(
        int $variantId,
        ?int $packUnitId,
        int $qty,
        ?array $customer = null,
        ?string $at = null,
    ): ResolvedPrice {
        if ($qty < 1) {
            throw new ValidationFailed('Quantity must be at least 1.', ['qty' => 'Must be 1 or more.']);
        }

        $at ??= $this->clock->nowString();
        $group = $this->effectiveGroup($customer);
        $baseList = $this->prices->basePriceList();

        if ($group === null && $baseList === null) {
            throw new PriceNotFound($variantId, $packUnitId);
        }

        $packPieces = $this->packPieces($packUnitId);

        // 1. A price agreed with this customer (§8.2 customer_prices).
        if ($customer !== null && isset($customer['id'])) {
            $special = $this->prices->customerPrice((int) $customer['id'], $variantId, $packUnitId, $at);
            if ($special !== null) {
                return new ResolvedPrice(
                    variantId: $variantId,
                    packUnitId: $packUnitId,
                    qty: $qty,
                    unitAmount: new Money((int) $special['amount_kobo']),
                    source: PriceSource::CUSTOMER,
                    minQty: 1,
                    packPieces: $packPieces,
                    customerId: (int) $customer['id'],
                );
            }
        }

        $groupListId = $group !== null ? (int) $group['price_list_id'] : null;
        $groupListIsBase = $baseList !== null && $groupListId === (int) $baseList['id'];

        // 2. The customer's group price list, best tier that their quantity reaches.
        if ($groupListId !== null && !$groupListIsBase) {
            $tier = $this->prices->bestTier($groupListId, $variantId, $packUnitId, $qty);
            $groupId = (int) $group['id'];

            if ($tier === null && (int) ($group['ignore_item_minimums'] ?? 0) === 1) {
                // R1: an approved wholesaler buying below the tier minimum still
                // gets the lowest wholesale tier, never the retail price.
                $tier = $this->prices->lowestTier($groupListId, $variantId, $packUnitId);
            }

            if ($tier !== null) {
                $minQty = (int) $tier['min_qty'];

                return new ResolvedPrice(
                    variantId: $variantId,
                    packUnitId: $packUnitId,
                    qty: $qty,
                    unitAmount: new Money((int) $tier['amount_kobo']),
                    source: $minQty > 1 ? PriceSource::GROUP_TIER : PriceSource::GROUP,
                    minQty: $minQty,
                    packPieces: $packPieces,
                    priceListId: $groupListId,
                    customerId: isset($customer['id']) ? (int) $customer['id'] : null,
                );
            }
        }

        // 3. The base (retail) list, open to guests and every group.
        if ($baseList !== null) {
            $tier = $this->prices->bestTier((int) $baseList['id'], $variantId, $packUnitId, $qty);
            if ($tier !== null) {
                $minQty = (int) $tier['min_qty'];

                return new ResolvedPrice(
                    variantId: $variantId,
                    packUnitId: $packUnitId,
                    qty: $qty,
                    unitAmount: new Money((int) $tier['amount_kobo']),
                    source: $minQty > 1 ? PriceSource::BASE_TIER : PriceSource::BASE,
                    minQty: $minQty,
                    packPieces: $packPieces,
                    priceListId: (int) $baseList['id'],
                    customerId: isset($customer['id']) ? (int) $customer['id'] : null,
                );
            }
        }

        // 4. No price for this pack: derive from the piece price when allowed (§10.1).
        if ($packUnitId !== null && $packPieces > 1 && $this->derivePackPrices) {
            $piece = $this->resolve($variantId, null, $qty * $packPieces, $customer, $at);
            $derived = $piece->unitAmount->multiply($packPieces);

            return new ResolvedPrice(
                variantId: $variantId,
                packUnitId: $packUnitId,
                qty: $qty,
                unitAmount: $derived,
                source: $piece->source,
                minQty: $piece->minQty,
                packPieces: $packPieces,
                priceListId: $piece->priceListId,
                customerId: $piece->customerId,
            );
        }

        throw new PriceNotFound($variantId, $packUnitId, $groupListId);
    }

    /**
     * Resolve every line and return unit prices with a subtotal (§11.2, §18 /pricing/quote).
     *
     * @param list<array{variant_id:int,pack_unit_id?:int|null,qty:int}> $lines
     * @param array<string,mixed>|null $customer
     * @return array{subtotal_kobo:int,currency:string,lines:list<array<string,mixed>>}
     */
    public function quote(array $lines, ?array $customer = null, ?string $at = null): array
    {
        $subtotal = Money::zero();
        $resolved = [];

        foreach ($lines as $line) {
            $price = $this->resolve(
                (int) $line['variant_id'],
                $line['pack_unit_id'] ?? null,
                (int) $line['qty'],
                $customer,
                $at
            );
            $subtotal = $subtotal->add($price->lineTotal());
            $resolved[] = $price->toArray();
        }

        return [
            'subtotal_kobo' => $subtotal->minor(),
            'currency' => $subtotal->currency(),
            'lines' => $resolved,
        ];
    }

    /**
     * Set one price tier, audit-logged with before/after (§10.5).
     */
    public function setPrice(
        int $priceListId,
        int $variantId,
        ?int $packUnitId,
        int $minQty,
        Money $amount,
        Actor $actor,
    ): int {
        if ($minQty < 1) {
            throw new ValidationFailed('Minimum quantity must be at least 1.', ['min_qty' => 'Must be 1 or more.']);
        }

        $existing = $this->prices->bestTier($priceListId, $variantId, $packUnitId, $minQty);
        $priceId = $this->prices->upsertPrice($priceListId, $variantId, $packUnitId, $minQty, $amount->minor());

        $this->audit->log(
            $actor,
            'price.updated',
            'price',
            $priceId,
            $existing === null ? null : ['amount_kobo' => (int) $existing['amount_kobo']],
            [
                'price_list_id' => $priceListId,
                'variant_id' => $variantId,
                'pack_unit_id' => $packUnitId,
                'min_qty' => $minQty,
                'amount_kobo' => $amount->minor(),
            ]
        );

        return $priceId;
    }

    /**
     * The base (retail) list price for a line, used for the order-item snapshot
     * and the storefront "you saved" display (§8.4 list_price_kobo).
     */
    public function baseAmount(int $variantId, ?int $packUnitId, int $qty): ?Money
    {
        $base = $this->prices->basePriceList();
        if ($base === null) {
            return null;
        }

        $tier = $this->prices->bestTier((int) $base['id'], $variantId, $packUnitId, $qty);

        return $tier === null ? null : new Money((int) $tier['amount_kobo']);
    }

    /**
     * The group used for pricing: the customer's group only when approved,
     * otherwise the store's default (retail) group (§10.1, §11.1).
     *
     * @param array<string,mixed>|null $customer
     * @return array<string,mixed>|null
     */
    private function effectiveGroup(?array $customer): ?array
    {
        if ($customer !== null
            && ($customer['approval_status'] ?? 'approved') === 'approved'
            && isset($customer['group_id'])
        ) {
            $group = $this->prices->group((int) $customer['group_id']);
            if ($group !== null) {
                return $group;
            }
        }

        return $this->prices->defaultGroup();
    }

    private function packPieces(?int $packUnitId): int
    {
        if ($packUnitId === null) {
            return 1;
        }

        $pack = $this->prices->packUnit($packUnitId);
        if ($pack === null) {
            throw new ValidationFailed('Unknown pack unit.', ['pack_unit_id' => 'Not found.']);
        }

        return max(1, (int) $pack['pieces']);
    }
}
