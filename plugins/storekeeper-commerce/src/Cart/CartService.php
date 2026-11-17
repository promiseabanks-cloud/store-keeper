<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Cart;

use StoreKeeper\Commerce\Catalog\CatalogRepository;
use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Dates;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Core\Uuid;
use StoreKeeper\Commerce\Inventory\InventoryRepository;
use StoreKeeper\Commerce\Pricing\Exception\PriceNotFound;
use StoreKeeper\Commerce\Pricing\PricingService;

/**
 * The storefront cart (§22.5).
 *
 * It stores only *what* was chosen, never what it costs: every read re-resolves
 * the price through PricingService and the stock through the ledger, so a price
 * change, a tier change or a sold-out variant is visible on the next page load.
 * Nothing is reserved until checkout creates the order.
 */
final class CartService
{
    private const LIFETIME_MINUTES = 43200; // 30 days

    public function __construct(
        private readonly CartRepository $carts,
        private readonly CatalogRepository $catalog,
        private readonly PricingService $pricing,
        private readonly InventoryRepository $inventory,
        private readonly Clock $clock,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function find(string $tokenHash): ?array
    {
        $cart = $this->carts->byTokenHash($tokenHash);

        if ($cart === null) {
            return null;
        }

        if ($cart['status'] === 'active' && $cart['expires_at'] < $this->clock->nowString()) {
            return null;
        }

        return $cart;
    }

    /** @return array<string,mixed> */
    public function open(string $tokenHash, ?int $customerId = null): array
    {
        $cart = $this->carts->byTokenHash($tokenHash);

        // A converted cart and an expired one are both reused as a fresh cart;
        // inserting a second row would collide with the unique token hash.
        if ($cart !== null && ($cart['status'] !== 'active' || $cart['expires_at'] < $this->clock->nowString())) {
            return $this->reopen($cart, $customerId);
        }

        if ($cart !== null) {
            $changes = ['expires_at' => $this->expiry()];
            if ($customerId !== null && (int) ($cart['customer_id'] ?? 0) !== $customerId) {
                $changes['customer_id'] = $customerId;
            }
            $this->carts->update((int) $cart['id'], $changes);

            return $this->carts->byId((int) $cart['id']) ?? $cart;
        }

        $now = $this->clock->nowString();
        $cartId = $this->carts->create([
            'uuid' => Uuid::v4(),
            'token_hash' => $tokenHash,
            'customer_id' => $customerId,
            'currency' => Brand::CURRENCY,
            'status' => 'active',
            'converted_order_id' => null,
            'expires_at' => $this->expiry(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->carts->byId($cartId) ?? throw new \RuntimeException('Cart was not created.');
    }

    /**
     * @param array<string,mixed> $cart
     * @param array<string,mixed>|null $customer
     * @return array<string,mixed>
     */
    public function summary(array $cart, ?array $customer): array
    {
        $lines = [];
        $subtotal = 0;
        $weightGrams = 0;
        $errors = [];
        $variantIds = [];
        $rows = $this->carts->items((int) $cart['id']);

        $count = 0;
        foreach ($rows as $row) {
            $variantIds[] = (int) $row['variant_id'];
            $count += (int) $row['qty'];
        }

        $stock = $variantIds === [] ? [] : $this->inventory->availableOnlineMany($variantIds);

        foreach ($rows as $row) {
            $line = $this->describeLine($row, $customer, $stock);
            $subtotal += $line['line_total_kobo'];
            $weightGrams += $line['weight_grams'];

            if ($line['error'] !== null) {
                $errors[] = $line['product_name'] . ': ' . $line['error'];
            }

            $lines[] = $line;
        }

        return [
            'id' => (int) $cart['id'],
            'uuid' => (string) $cart['uuid'],
            'status' => (string) $cart['status'],
            'currency' => (string) $cart['currency'],
            'item_count' => $count,
            'lines' => $lines,
            'subtotal_kobo' => $subtotal,
            'weight_grams' => $weightGrams,
            'has_errors' => $errors !== [],
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string,mixed> $cart
     * @param array<string,mixed> $input variant_id, qty, optional pack_unit_id
     * @param array<string,mixed>|null $customer
     * @return array<string,mixed>
     */
    public function add(array $cart, array $input, ?array $customer): array
    {
        $cart = $this->active($cart);

        $variantId = (int) ($input['variant_id'] ?? 0);
        $qty = (int) ($input['qty'] ?? 1);
        $packUnitId = isset($input['pack_unit_id']) && (int) $input['pack_unit_id'] > 0
            ? (int) $input['pack_unit_id']
            : 0;

        if ($qty < 1) {
            throw new ValidationFailed('Choose at least one piece.', ['qty' => 'Must be 1 or more.']);
        }

        $this->assertSellable($variantId);

        $existing = $this->carts->line((int) $cart['id'], $variantId, $packUnitId);
        $wanted = $qty + ($existing === null ? 0 : (int) $existing['qty']);

        $this->assertStock($variantId, $packUnitId, $wanted);

        $now = $this->clock->nowString();
        if ($existing === null) {
            $this->carts->insertLine([
                'cart_id' => (int) $cart['id'],
                'variant_id' => $variantId,
                'pack_unit_id' => $packUnitId,
                'qty' => $qty,
                'added_at' => $now,
            ]);
        } else {
            $this->carts->updateQty((int) $existing['id'], $wanted);
        }

        $this->carts->update((int) $cart['id'], [
            'expires_at' => $this->expiry(),
            'updated_at' => $now,
        ]);

        return $this->summary($this->refresh($cart), $customer);
    }

    /**
     * @param array<string,mixed> $cart
     * @param array<string,mixed>|null $customer
     * @return array<string,mixed>
     */
    public function setQty(array $cart, int $lineId, int $qty, ?array $customer): array
    {
        $cart = $this->active($cart);

        $line = $this->carts->lineById((int) $cart['id'], $lineId);

        if ($line === null) {
            throw new ValidationFailed('That item is not in your cart.');
        }

        if ($qty < 1) {
            return $this->remove($cart, $lineId, $customer);
        }

        $packUnitId = (int) $line['pack_unit_id'];
        $this->assertStock((int) $line['variant_id'], $packUnitId, $qty);

        $this->carts->updateQty($lineId, $qty);
        $this->carts->update((int) $cart['id'], [
            'expires_at' => $this->expiry(),
            'updated_at' => $this->clock->nowString(),
        ]);

        return $this->summary($this->refresh($cart), $customer);
    }

    /**
     * @param array<string,mixed> $cart
     * @param array<string,mixed>|null $customer
     * @return array<string,mixed>
     */
    public function remove(array $cart, int $lineId, ?array $customer): array
    {
        $cart = $this->active($cart);

        $line = $this->carts->lineById((int) $cart['id'], $lineId);

        if ($line === null) {
            throw new ValidationFailed('That item is not in your cart.');
        }

        $this->carts->deleteLine($lineId);

        return $this->summary($this->refresh($cart), $customer);
    }

    /** @param array<string,mixed> $cart */
    public function clear(array $cart): void
    {
        $this->carts->clear((int) $cart['id']);
    }

    /** @param array<string,mixed> $cart */
    public function lineCount(array $cart): int
    {
        return $this->carts->lineCount((int) $cart['id']);
    }

    /**
     * A converted or abandoned cart is reused as a clean cart, with a new uuid so
     * its next checkout cannot replay the order it already produced. Without this
     * a returning visitor's cart stayed "converted" and could never check out
     * again.
     *
     * @param array<string,mixed> $cart
     * @return array<string,mixed>
     */
    private function reopen(array $cart, ?int $customerId): array
    {
        $this->carts->clear((int) $cart['id']);
        $now = $this->clock->nowString();
        $this->carts->update((int) $cart['id'], [
            'uuid' => Uuid::v4(),
            'customer_id' => $customerId ?? $cart['customer_id'],
            'status' => 'active',
            'converted_order_id' => null,
            'expires_at' => $this->expiry(),
            'updated_at' => $now,
        ]);

        return $this->carts->byId((int) $cart['id']) ?? throw new \RuntimeException('Cart was not reopened.');
    }

    /**
     * Attach the browsing cart to the customer who just signed in, folding in
     * any active cart they left on another device.
     *
     * The browser's own cookie keeps working; the other cart rows are emptied and
     * marked abandoned. Quantities are capped at what is in stock rather than
     * refused, because a merge happens without a shopper watching it.
     *
     * @param array<string,mixed> $cart
     * @return array<string,mixed>
     */
    public function claim(array $cart, int $customerId): array
    {
        $cart = $this->active($cart);

        foreach ($this->carts->activeForCustomer($customerId) as $other) {
            if ((int) $other['id'] === (int) $cart['id']) {
                continue;
            }

            foreach ($this->carts->items((int) $other['id']) as $line) {
                $this->mergeLine($cart, $line);
            }

            $this->carts->clear((int) $other['id']);
            $this->carts->update((int) $other['id'], ['status' => 'abandoned']);
        }

        $this->carts->update((int) $cart['id'], [
            'customer_id' => $customerId,
            'expires_at' => $this->expiry(),
            'updated_at' => $this->clock->nowString(),
        ]);

        return $this->refresh($cart);
    }

    /** @param array<string,mixed> $cart @param array<string,mixed> $line */
    private function mergeLine(array $cart, array $line): void
    {
        $variantId = (int) $line['variant_id'];
        $packUnitId = (int) $line['pack_unit_id'];

        try {
            $this->assertSellable($variantId);
        } catch (ValidationFailed) {
            return;
        }

        $existing = $this->carts->line((int) $cart['id'], $variantId, $packUnitId);
        $wanted = (int) $line['qty'] + ($existing === null ? 0 : (int) $existing['qty']);
        $qty = $this->fits($variantId, $packUnitId, $wanted);

        if ($qty < 1) {
            return;
        }

        if ($existing === null) {
            $this->carts->insertLine([
                'cart_id' => (int) $cart['id'],
                'variant_id' => $variantId,
                'pack_unit_id' => $packUnitId,
                'qty' => $qty,
                'added_at' => $this->clock->nowString(),
            ]);

            return;
        }

        $this->carts->updateQty((int) $existing['id'], $qty);
    }

    /** The largest quantity of a variant/pack the shelf can supply right now. */
    private function fits(int $variantId, int $packUnitId, int $wanted): int
    {
        $available = $this->inventory->availableOnline($variantId);

        if ($packUnitId === 0) {
            return max(0, min($wanted, $available));
        }

        $pieces = max(1, (int) ($this->catalog->packUnit($packUnitId)['pieces'] ?? 1));

        return max(0, min($wanted, intdiv($available, $pieces)));
    }

    /**
     * A write to a cart that has already been converted reopens it first.
     *
     * @param array<string,mixed> $cart
     * @return array<string,mixed>
     */
    private function active(array $cart): array
    {
        return $cart['status'] === 'active' ? $cart : $this->reopen($cart, null);
    }

    /** @param array<string,mixed> $cart */
    public function markConverted(array $cart, int $orderId): void
    {
        $this->carts->clear((int) $cart['id']);
        $this->carts->update((int) $cart['id'], [
            'status' => 'converted',
            'converted_order_id' => $orderId,
            'updated_at' => $this->clock->nowString(),
        ]);
    }

    /**
     * @param array<string,mixed> $cart
     * @return array<string,mixed>
     */
    private function refresh(array $cart): array
    {
        return $this->carts->byId((int) $cart['id']) ?? $cart;
    }

    private function expiry(): string
    {
        return Dates::addMinutes($this->clock->nowString(), self::LIFETIME_MINUTES);
    }

    private function assertSellable(int $variantId): void
    {
        $variant = $this->catalog->variant($variantId);
        if ($variant === null || ($variant['status'] ?? 'active') !== 'active') {
            throw new ValidationFailed('That product is no longer available.', ['variant_id' => 'Unavailable.']);
        }

        $product = $this->catalog->product((int) $variant['product_id']);
        if ($product === null || (string) $product['status'] !== 'active' || (string) $product['visibility'] === 'hidden') {
            throw new ValidationFailed('That product is no longer available.', ['variant_id' => 'Unavailable.']);
        }
    }

    private function assertStock(int $variantId, int $packUnitId, int $qty): void
    {
        $pieces = $this->pieces($packUnitId, $qty);
        $available = $this->inventory->availableOnline($variantId);

        if ($pieces > $available) {
            throw new ValidationFailed(
                $available <= 0 ? 'That product is out of stock.' : sprintf('Only %d left in stock.', $available),
                ['qty' => $available <= 0 ? 'Out of stock.' : sprintf('Maximum %d.', $available)]
            );
        }
    }

    private function pieces(int $packUnitId, int $qty): int
    {
        if ($packUnitId === 0) {
            return $qty;
        }

        $pack = $this->catalog->packUnit($packUnitId);

        return $qty * max(1, (int) ($pack['pieces'] ?? 1));
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed>|null $customer
     * @param array<int,int> $stock
     * @return array<string,mixed>
     */
    private function describeLine(array $row, ?array $customer, array $stock): array
    {
        $variantId = (int) $row['variant_id'];
        $qty = (int) $row['qty'];
        $packUnitId = (int) $row['pack_unit_id'] > 0 ? (int) $row['pack_unit_id'] : null;

        $variant = $this->catalog->variant($variantId);
        $product = $variant === null ? null : $this->catalog->product((int) $variant['product_id']);

        $name = $product === null ? 'Unavailable product' : (string) $product['name'];
        $title = $variant === null ? '' : (string) $variant['title'];
        $sku = $variant === null ? '' : (string) $variant['sku'];
        $slug = $product === null ? null : (string) $product['slug'];

        $pack = $packUnitId === null ? null : $this->catalog->packUnit($packUnitId);

        $error = null;
        $amount = null;
        $source = null;
        $piecesPerUnit = 1;

        if ($variant === null || $product === null || (string) $product['status'] !== 'active' || (string) $product['visibility'] === 'hidden') {
            $error = 'No longer available';
        } else {
            try {
                $resolved = $this->pricing->resolve($variantId, $packUnitId, $qty, $customer);
                $amount = $resolved->unitAmount->minor();
                $source = $resolved->source;
                $piecesPerUnit = max(1, $resolved->packPieces);
            } catch (PriceNotFound) {
                $error = 'Ask the store for a price';
            }
        }

        $pieceWeight = $variant === null ? 0 : max(0, (int) ($variant['weight_grams'] ?? 0));
        $weightGrams = $error === null ? $pieceWeight * $qty * $piecesPerUnit : 0;

        $available = $stock[$variantId] ?? 0;
        if ($error === null && $qty * $piecesPerUnit > $available) {
            $error = $available <= 0 ? 'Out of stock' : sprintf('Only %d left', $available);
        }

        return [
            'id' => (int) $row['id'],
            'variant_id' => $variantId,
            'pack_unit_id' => $packUnitId,
            'pack_name' => $packUnitId === null ? null : (string) ($pack['name'] ?? 'pack'),
            'qty' => $qty,
            'product_name' => $name,
            'variant_title' => $title,
            'sku' => $sku,
            'slug' => $slug,
            'unit_price_kobo' => $amount,
            'line_total_kobo' => $amount === null ? 0 : $amount * $qty,
            'weight_grams' => $weightGrams,
            'price_source' => $source,
            'available' => $available,
            'error' => $error,
        ];
    }
}
