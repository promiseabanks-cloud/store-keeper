<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Storefront;

use StoreKeeper\Commerce\Cart\CartService;
use StoreKeeper\Commerce\Core\Exception\StoreKeeperException;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Customers\CustomerRepository;
use StoreKeeper\Commerce\Orders\OrderRepository;

/**
 * A signed-in shopper's own data (§11.1, §11.2 reorder).
 *
 * One place that reads a customer's orders, addresses and repeats an order, so
 * the account screen has no queries of its own and the ownership rule (an order
 * is only repeatable by the customer it belongs to) is testable.
 */
final class StorefrontAccount
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly CartService $cart,
        private readonly CustomerRepository $customers,
    ) {
    }

    /** @return list<array<string,mixed>> newest first */
    public function orders(int $customerId, int $limit = 50): array
    {
        return $this->orders->listDetailed(['customer_id' => $customerId], $limit);
    }

    /** @return list<array<string,mixed>> */
    public function addresses(int $customerId): array
    {
        return $this->customers->addresses($customerId);
    }

    /**
     * Put an order's lines back in the cart, keeping whatever is still sellable.
     *
     * A line that has since sold out or been archived is skipped and named, so a
     * repeat never half-fails with a fatal error.
     *
     * @param array<string,mixed> $customer
     * @param array<string,mixed> $cart
     * @return array{added:int,skipped:list<string>}
     */
    public function repeat(array $customer, string $orderNumber, array $cart): array
    {
        $order = $this->orders->orderByNumber($orderNumber);

        if ($order === null || (int) ($order['customer_id'] ?? 0) !== (int) $customer['id']) {
            throw new ValidationFailed('That order is not yours to repeat.');
        }

        $added = 0;
        $skipped = [];

        foreach ($this->orders->items((int) $order['id']) as $item) {
            $packUnitId = (int) ($item['pack_unit_id'] ?? 0);

            try {
                $this->cart->add($cart, [
                    'variant_id' => (int) $item['variant_id'],
                    'pack_unit_id' => $packUnitId > 0 ? $packUnitId : null,
                    'qty' => (int) $item['qty'],
                ], $customer);
                $added++;
            } catch (StoreKeeperException) {
                $skipped[] = trim((string) $item['product_name'] . ' — ' . (string) $item['variant_title']);
            }
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'Awaiting payment',
            'paid' => 'Paid',
            'packed' => 'Packed',
            'ready_for_pickup' => 'Ready for pickup',
            'dispatched' => 'On the way',
            'delivered' => 'Delivered',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'refunded' => 'Refunded',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}
