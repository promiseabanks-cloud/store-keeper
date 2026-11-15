<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Orders;

use StoreKeeper\Commerce\Catalog\CatalogRepository;
use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Audit\AuditLogger;
use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;
use StoreKeeper\Commerce\Core\Dates;
use StoreKeeper\Commerce\Core\Exception\NotFound;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Core\Hooks\Hooks;
use StoreKeeper\Commerce\Core\Uuid;
use StoreKeeper\Commerce\Inventory\InventoryService;
use StoreKeeper\Commerce\Pricing\PricingService;
use StoreKeeper\Commerce\Pricing\PriceSource;

/**
 * Order engine core (§11.2, §12): create an order from any channel, reserve its
 * stock in the same transaction, cancel it (releasing reservations) and hand it
 * over (committing them). Payment alone never moves stock (D-012).
 */
final class OrderService
{
    private const CHANNELS = ['web', 'whatsapp', 'instagram', 'phone', 'counter', 'other'];

    private const GATEWAY_METHODS = ['paystack', 'flutterwave', 'gateway3'];

    public function __construct(
        private readonly Connection $db,
        private readonly OrderRepository $orders,
        private readonly CatalogRepository $catalog,
        private readonly PricingService $pricing,
        private readonly InventoryService $inventory,
        private readonly Hooks $hooks,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly string $orderPrefix = 'SK',
    ) {
    }

    /**
     * @param array<string,mixed> $input
     * @return array{order:array<string,mixed>,items:list<array<string,mixed>>,replayed:bool}
     */
    public function create(array $input, Actor $actor): array
    {
        $idempotencyKey = trim((string) ($input['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            throw new ValidationFailed('An idempotency key is required for every order.', ['idempotency_key' => 'Required.']);
        }

        if (strlen($idempotencyKey) > 64) {
            throw new ValidationFailed('The idempotency key is too long.', ['idempotency_key' => 'Maximum 64 characters.']);
        }

        $existing = $this->orders->orderByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return [
                'order' => $existing,
                'items' => $this->orders->items((int) $existing['id']),
                'replayed' => true,
            ];
        }

        $channel = (string) ($input['channel'] ?? 'other');
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new ValidationFailed('Unknown order channel.', ['channel' => implode(', ', self::CHANNELS)]);
        }

        $isDraft = !empty($input['draft']);
        $handedOver = !empty($input['handed_over_now']);
        $lines = $input['lines'] ?? [];

        if (!is_array($lines) || $lines === []) {
            throw new ValidationFailed('An order needs at least one line.', ['lines' => 'Required.']);
        }

        if ($handedOver && $isDraft) {
            throw new ValidationFailed('An order cannot be both a draft and handed over.');
        }

        $customer = $this->resolveCustomer($input);
        $group = $this->resolveGroup($input, $customer);
        $locationId = $this->resolveLocation($input);
        $currency = (string) ($input['currency'] ?? \StoreKeeper\Commerce\Core\Brand::CURRENCY);

        $preparedLines = [];
        $subtotal = 0;

        foreach ($lines as $line) {
            $prepared = $this->prepareLine($line, $customer, $currency, !empty($input['allow_manual_price']));
            $subtotal += $prepared['line_total_kobo'];
            $preparedLines[] = $prepared;
        }

        $discount = max(0, (int) ($input['discount_kobo'] ?? 0));
        if ($discount > $subtotal) {
            throw new ValidationFailed('The discount cannot exceed the subtotal.', ['discount_kobo' => 'Too large.']);
        }

        $shipping = max(0, (int) ($input['shipping_kobo'] ?? 0));
        $fee = max(0, (int) ($input['fee_kobo'] ?? 0));
        $total = $subtotal - $discount + $shipping + $fee;

        $paymentMethod = (string) ($input['payment_method'] ?? 'bank_transfer');
        $paidNow = $handedOver && in_array($paymentMethod, ['cash', 'pos_terminal'], true) ? $total : 0;

        $paymentStatus = PaymentStatus::UNPAID;
        if ($paidNow > 0 && $paidNow >= $total) {
            $paymentStatus = PaymentStatus::PAID;
        } elseif ($paymentMethod === 'pay_on_delivery') {
            $paymentStatus = PaymentStatus::PAY_ON_DELIVERY;
        }

        $status = $isDraft
            ? OrderStatus::DRAFT
            : ($paidNow >= $total && $total > 0 ? OrderStatus::COMPLETED : OrderStatus::OPEN);

        $now = $this->clock->nowString();
        $holdExpiresAt = $isDraft ? null : $this->holdExpiry($input);
        $publicToken = Uuid::publicToken();

        $result = $this->db->transaction(function () use (
            $input,
            $actor,
            $channel,
            $customer,
            $group,
            $locationId,
            $preparedLines,
            $subtotal,
            $discount,
            $shipping,
            $fee,
            $total,
            $paymentStatus,
            $status,
            $handedOver,
            $holdExpiresAt,
            $publicToken,
            $currency,
            $paidNow,
            $paymentMethod,
            $idempotencyKey,
            $now,
        ): array {
            $orderId = $this->orders->createOrder([
                'uuid' => Uuid::v4(),
                'number' => $this->orders->nextNumber($this->orderPrefix),
                'channel' => $channel,
                'status' => $status,
                'payment_status' => $paymentStatus,
                'fulfilment_status' => $handedOver ? FulfilmentStatus::FULFILLED : FulfilmentStatus::UNFULFILLED,
                'customer_id' => $customer === null ? null : (int) $customer['id'],
                'customer_group_id' => (int) $group['id'],
                'location_id' => $locationId,
                'currency' => $currency,
                'subtotal_kobo' => $subtotal,
                'discount_kobo' => $discount,
                'shipping_kobo' => $shipping,
                'fee_kobo' => $fee,
                'total_kobo' => $total,
                'paid_kobo' => $paidNow,
                'refunded_kobo' => 0,
                'shipping_method_id' => $input['shipping_method_id'] ?? null,
                'shipping_address' => isset($input['shipping_address'])
                    ? json_encode($input['shipping_address'], JSON_THROW_ON_ERROR)
                    : null,
                'hold_expires_at' => $holdExpiresAt,
                'public_token' => $publicToken,
                'idempotency_key' => $idempotencyKey,
                'created_by_type' => $this->createdByType($actor),
                'created_by_id' => $actor->id,
                'device_id' => $input['device_id'] ?? null,
                'client_created_at' => Dates::fromIso($input['client_created_at'] ?? null),
                'customer_note' => $input['customer_note'] ?? null,
                'staff_note' => $input['staff_note'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $itemIds = [];

            foreach ($preparedLines as $line) {
                // Reserve from the location that actually holds the stock (D-005);
                // the order-level location only supplies the default.
                $lineLocationId = $line['location_id'] ?? $locationId;

                $itemId = $this->orders->createItem([
                    'order_id' => $orderId,
                    'variant_id' => $line['variant_id'],
                    'product_name' => $line['product_name'],
                    'variant_title' => $line['variant_title'],
                    'sku' => $line['sku'],
                    'image_attachment_id' => $line['image_attachment_id'] ?? null,
                    'pack_unit_id' => $line['pack_unit_id'],
                    'pack_name' => $line['pack_name'],
                    'pack_pieces' => $line['pack_pieces'],
                    'qty' => $line['qty'],
                    'qty_pieces' => $line['qty_pieces'],
                    'qty_fulfilled_pieces' => $handedOver ? $line['qty_pieces'] : 0,
                    'unit_price_kobo' => $line['unit_price_kobo'],
                    'list_price_kobo' => $line['list_price_kobo'],
                    'price_source' => $line['price_source'],
                    'discount_kobo' => 0,
                    'line_total_kobo' => $line['line_total_kobo'],
                    'location_id' => $lineLocationId,
                ]);
                $itemIds[] = $itemId;

                if ($status === OrderStatus::OPEN || $status === OrderStatus::COMPLETED) {
                    if ($handedOver) {
                        $this->inventory->counterSale(
                            $line['variant_id'],
                            $lineLocationId,
                            $line['qty_pieces'],
                            $actor,
                            $orderId,
                            $itemId,
                            isset($input['device_id']) ? (int) $input['device_id'] : null,
                            Dates::fromIso($input['client_created_at'] ?? null)
                        );
                    } else {
                        $this->inventory->reserve(
                            $line['variant_id'],
                            $lineLocationId,
                            $line['qty_pieces'],
                            $actor,
                            $orderId,
                            $itemId
                        );
                    }
                }
            }

            if ($paidNow > 0) {
                $this->db->insert($this->db->prefix() . 'payments', [
                    'uuid' => Uuid::v4(),
                    'order_id' => $orderId,
                    'method' => $paymentMethod,
                    'status' => 'succeeded',
                    'amount_kobo' => $paidNow,
                    'gateway_reference' => null,
                    'gateway_fee_kobo' => null,
                    'gateway_payload' => null,
                    'proof_attachment_id' => null,
                    'sender_name' => null,
                    'confirmed_by' => $actor->id,
                    'confirmed_at' => $now,
                    'idempotency_key' => $idempotencyKey . '-p1',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return ['order_id' => $orderId, 'item_ids' => $itemIds];
        });

        $order = $this->orders->order($result['order_id']) ?? [];
        $items = $this->orders->items((int) $result['order_id']);

        $this->audit->log($actor, 'order.created', 'order', (int) $result['order_id'], null, [
            'number' => $order['number'] ?? null,
            'total_kobo' => $total,
            'channel' => $channel,
            'status' => $status,
        ]);
        $this->hooks->do('order/created', ['order' => $order, 'items' => $items]);
        if (($order['status'] ?? null) === OrderStatus::COMPLETED) {
            $this->hooks->do('order/completed', ['order' => $order]);
        }

        return ['order' => $order, 'items' => $items, 'replayed' => false];
    }

    /**
     * Cancel an open order and release every reservation still held for it (§12.1).
     *
     * @return array<string,mixed>
     */
    public function cancel(int $orderId, string $reason, Actor $actor): array
    {
        $order = $this->orders->order($orderId);
        if ($order === null) {
            throw new NotFound('order', $orderId);
        }

        if ($order['status'] === OrderStatus::CANCELLED) {
            return $order;
        }

        OrderStatus::assertTransition((string) $order['status'], OrderStatus::CANCELLED);

        $this->db->transaction(function () use ($orderId, $reason, $actor): void {
            $this->orders->updateOrder($orderId, [
                'status' => OrderStatus::CANCELLED,
                'cancelled_at' => $this->clock->nowString(),
                'cancel_reason' => $reason,
            ]);
            $this->inventory->releaseReservationsForOrder($orderId, $actor, 'order_cancelled');
        });

        $cancelled = $this->orders->order($orderId) ?? [];
        $this->audit->log($actor, 'order.cancelled', 'order', $orderId, [
            'status' => $order['status'],
        ], ['status' => OrderStatus::CANCELLED, 'reason' => $reason]);
        $this->hooks->do('order/cancelled', ['order' => $cancelled, 'reason' => $reason]);

        return $cancelled;
    }

    /**
     * Hand over the goods now: commit reservations and mark the order fulfilled,
     * completing it when it is fully paid (§11.2 "handed over now", §12.1).
     *
     * @return array<string,mixed>
     */
    public function handover(int $orderId, Actor $actor): array
    {
        $order = $this->orders->order($orderId);
        if ($order === null) {
            throw new NotFound('order', $orderId);
        }

        if ($order['status'] === OrderStatus::CANCELLED) {
            throw new \StoreKeeper\Commerce\Orders\Exception\InvalidTransition((string) $order['status'], OrderStatus::COMPLETED);
        }

        $this->db->transaction(function () use ($orderId, $actor): void {
            $this->inventory->commitReservationsForOrder($orderId, $actor);

            foreach ($this->orders->items($orderId) as $item) {
                $this->orders->updateItem((int) $item['id'], [
                    'qty_fulfilled_pieces' => (int) $item['qty_pieces'],
                ]);
            }

            $this->orders->updateOrder($orderId, [
                'fulfilment_status' => FulfilmentStatus::FULFILLED,
            ]);
        });

        $updated = $this->refreshStatuses($orderId, $actor);

        $this->audit->log($actor, 'order.handed_over', 'order', $orderId);
        $this->hooks->do('order/handed_over', ['order' => $updated]);

        return $updated;
    }

    /**
     * Record a manual payment against an order (bank transfer, cash, POS
     * terminal) and recompute the order's payment status (§12.2).
     *
     * @param array<string,mixed> $data method, amount_kobo, reference, note
     * @return array<string,mixed>
     */
    public function recordPayment(int $orderId, array $data, Actor $actor): array
    {
        $order = $this->orders->order($orderId);
        if ($order === null) {
            throw new NotFound('order', $orderId);
        }

        if ($order['status'] === OrderStatus::CANCELLED) {
            throw new ValidationFailed('Cancelled orders cannot take payments.', ['order' => 'Cancelled.']);
        }

        $amount = (int) ($data['amount_kobo'] ?? 0);
        $outstanding = max(0, (int) $order['total_kobo'] - (int) $order['paid_kobo']);

        if ($amount <= 0) {
            $amount = $outstanding;
        }

        if ($amount <= 0) {
            throw new ValidationFailed('There is nothing left to pay on this order.');
        }

        if ($amount > $outstanding) {
            throw new ValidationFailed('The payment is larger than the outstanding balance.', [
                'amount_kobo' => 'Outstanding is ' . $outstanding . ' kobo.',
            ]);
        }

        $method = (string) ($data['method'] ?? 'bank_transfer');
        if (!in_array($method, ['bank_transfer', 'virtual_account', 'cash', 'pos_terminal', 'paystack', 'flutterpay', 'opay', 'flutterwave', 'pay_on_delivery', 'gateway3'], true)) {
            throw new ValidationFailed('Unknown payment method.', ['method' => 'Unsupported.']);
        }

        $now = $this->clock->nowString();

        $this->db->transaction(function () use ($orderId, $amount, $method, $data, $actor, $now): void {
            $this->db->insert($this->db->prefix() . 'payments', [
                'uuid' => Uuid::v4(),
                'order_id' => $orderId,
                'method' => $method,
                'status' => 'succeeded',
                'amount_kobo' => $amount,
                'gateway_reference' => $data['reference'] ?? null,
                'gateway_fee_kobo' => null,
                'gateway_payload' => null,
                'proof_attachment_id' => null,
                'sender_name' => $data['note'] ?? null,
                'confirmed_by' => $actor->id,
                'confirmed_at' => $now,
                'idempotency_key' => substr('pay-' . $orderId . '-' . bin2hex(random_bytes(8)), 0, 64),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        $updated = $this->refreshStatuses($orderId, $actor);

        $this->audit->log($actor, 'order.payment_recorded', 'order', $orderId, null, [
            'amount_kobo' => $amount,
            'method' => $method,
        ]);
        $this->hooks->do('order/payment_recorded', ['order' => $updated, 'amount_kobo' => $amount, 'method' => $method]);

        return $updated;
    }

    /**
     * Recompute payment and overall status from the payment rows (§12.2).
     *
     * @return array<string,mixed>
     */
    public function refreshStatuses(int $orderId, Actor $actor): array
    {
        $order = $this->orders->order($orderId);
        if ($order === null) {
            throw new NotFound('order', $orderId);
        }

        $payments = $this->orders->payments($orderId);
        $paid = 0;
        $refunded = 0;
        $paymentRows = [];

        foreach ($payments as $payment) {
            $paymentRows[] = ['status' => (string) $payment['status'], 'amount_kobo' => (int) $payment['amount_kobo']];
            if ($payment['status'] === 'succeeded') {
                $paid += (int) $payment['amount_kobo'];
            }
        }

        $paymentStatus = PaymentStatus::derive(
            (int) $order['total_kobo'],
            $paid,
            $refunded,
            $paymentRows,
            $order['payment_status'] === PaymentStatus::PAY_ON_DELIVERY
        );

        $items = $this->orders->items($orderId);
        $fulfilmentStatus = FulfilmentStatus::derive($items);

        $status = (string) $order['status'];
        if ($status === OrderStatus::OPEN
            && $fulfilmentStatus === FulfilmentStatus::FULFILLED
            && $paymentStatus === PaymentStatus::PAID
        ) {
            $status = OrderStatus::COMPLETED;
        }

        $before = [
            'status' => $order['status'],
            'payment_status' => $order['payment_status'],
            'fulfilment_status' => $order['fulfilment_status'],
        ];

        $this->orders->updateOrder($orderId, [
            'status' => $status,
            'payment_status' => $paymentStatus,
            'fulfilment_status' => $fulfilmentStatus,
            'paid_kobo' => $paid,
            'refunded_kobo' => $refunded,
            'completed_at' => $status === OrderStatus::COMPLETED ? ($order['completed_at'] ?? $this->clock->nowString()) : $order['completed_at'],
        ]);

        $updated = $this->orders->order($orderId) ?? [];

        if ($before['payment_status'] !== $paymentStatus) {
            $this->hooks->do('order/payment_status_changed', [
                'order' => $updated,
                'from' => $before['payment_status'],
                'to' => $paymentStatus,
            ]);
        }

        if ($before['fulfilment_status'] !== $fulfilmentStatus) {
            $this->hooks->do('order/fulfilment_status_changed', [
                'order' => $updated,
                'from' => $before['fulfilment_status'],
                'to' => $fulfilmentStatus,
            ]);
        }

        if ($before['status'] !== $status && $status === OrderStatus::COMPLETED) {
            $this->hooks->do('order/completed', ['order' => $updated]);
        }

        return $updated;
    }

    /** @return array<string,mixed>|null */
    public function find(int $orderId): ?array
    {
        return $this->orders->order($orderId);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return $this->orders->orderByUuid($uuid);
    }

    /** @return array<string,mixed>|null */
    public function findByPublicToken(string $number, string $token): ?array
    {
        return $this->orders->orderByPublicToken($number, $token);
    }

    /** @return list<array<string,mixed>> */
    public function items(int $orderId): array
    {
        return $this->orders->items($orderId);
    }

    /** @return list<array<string,mixed>> */
    public function payments(int $orderId): array
    {
        return $this->orders->payments($orderId);
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return $this->orders->list($filters, $limit, $offset);
    }

    /**
     * @param array<string,mixed> $line
     * @param array<string,mixed>|null $customer
     * @return array<string,mixed>
     */
    private function prepareLine(array $line, ?array $customer, string $currency, bool $allowManualPrice): array
    {
        $variantId = (int) ($line['variant_id'] ?? 0);
        $variant = $this->catalog->variant($variantId);
        if ($variant === null) {
            throw new NotFound('variant', $variantId);
        }

        $product = $this->catalog->product((int) $variant['product_id']);
        if ($product === null) {
            throw new NotFound('product', (int) $variant['product_id']);
        }

        $qty = (int) ($line['qty'] ?? 0);
        if ($qty < 1) {
            throw new ValidationFailed('Every line needs a quantity of at least 1.', ['qty' => 'Must be 1 or more.']);
        }

        $packUnitId = isset($line['pack_unit_id']) ? (int) $line['pack_unit_id'] : null;

        if (isset($line['unit_price_kobo'])) {
            if (!$allowManualPrice) {
                throw new ValidationFailed('This user cannot override prices.', ['unit_price_kobo' => 'Not permitted.']);
            }
            $unitPrice = (int) $line['unit_price_kobo'];
            $source = PriceSource::MANUAL;
            $packPieces = 1;
            if ($packUnitId !== null) {
                $pack = $this->catalog->packUnit($packUnitId);
                $packPieces = $pack === null ? 1 : max(1, (int) $pack['pieces']);
            }
            $priceListId = null;
        } else {
            $resolved = $this->pricing->resolve($variantId, $packUnitId, $qty, $customer);
            $unitPrice = $resolved->unitAmount->minor();
            $source = $resolved->source;
            $packPieces = $resolved->packPieces;
        }

        $qtyPieces = $qty * $packPieces;
        $lineTotal = $unitPrice * $qty;
        $baseAmount = $this->pricing->baseAmount($variantId, $packUnitId, $qty);

        return [
            'variant_id' => $variantId,
            'product_name' => (string) $product['name'],
            'variant_title' => (string) $variant['title'],
            'sku' => (string) $variant['sku'],
            'image_attachment_id' => $this->firstImageId($variantId),
            'pack_unit_id' => $packUnitId,
            'pack_name' => $packUnitId === null ? 'piece' : (string) ($this->catalog->packUnit($packUnitId)['name'] ?? 'pack'),
            'pack_pieces' => $packPieces,
            'qty' => $qty,
            'qty_pieces' => $qtyPieces,
            'unit_price_kobo' => $unitPrice,
            'list_price_kobo' => $baseAmount?->minor() ?? $unitPrice,
            'price_source' => $source,
            'line_total_kobo' => $lineTotal,
            'currency' => $currency,
            'location_id' => $this->inventory->findReservationLocation($variantId, $qtyPieces),
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>|null
     */
    private function resolveCustomer(array $input): ?array
    {
        if (isset($input['customer']) && is_array($input['customer'])) {
            return $input['customer'];
        }

        if (!empty($input['customer_id'])) {
            $customer = $this->orders->customer((int) $input['customer_id']);
            if ($customer === null) {
                throw new NotFound('customer', (int) $input['customer_id']);
            }

            return $customer;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $customer
     * @return array<string,mixed>
     */
    private function resolveGroup(array $input, ?array $customer): array
    {
        if (!empty($input['customer_group_id'])) {
            $group = $this->orders->customerGroup((int) $input['customer_group_id']);
            if ($group === null) {
                throw new NotFound('customer group', (int) $input['customer_group_id']);
            }

            return $group;
        }

        if ($customer !== null && ($customer['approval_status'] ?? 'approved') === 'approved') {
            $group = $this->orders->customerGroup((int) $customer['group_id']);
            if ($group !== null) {
                return $group;
            }
        }

        $default = $this->orders->defaultGroup();
        if ($default === null) {
            throw new ValidationFailed('This store has no customer groups yet.');
        }

        return $default;
    }

    /** @param array<string,mixed> $input */
    private function resolveLocation(array $input): int
    {
        if (!empty($input['location_id'])) {
            return (int) $input['location_id'];
        }

        $locations = $this->inventory->locations(true);
        if ($locations === []) {
            throw new ValidationFailed('This store has no sellable location yet.');
        }

        return (int) $locations[0]['id'];
    }

    /** @param array<string,mixed> $input */
    private function holdExpiry(array $input): ?string
    {
        $now = $this->clock->nowString();

        if (array_key_exists('hold_minutes', $input)) {
            $minutes = $input['hold_minutes'];
            return $minutes === null ? null : Dates::addMinutes($now, (int) $minutes);
        }

        $method = (string) ($input['payment_method'] ?? 'bank_transfer');

        return match (true) {
            in_array($method, self::GATEWAY_METHODS, true) => Dates::addMinutes($now, 30),
            in_array($method, ['bank_transfer', 'virtual_account'], true) => Dates::addMinutes($now, 1440),
            default => null,
        };
    }

    private function createdByType(Actor $actor): string
    {
        return match ($actor->type) {
            Actor::STAFF, Actor::OWNER_IMPERSONATION => 'staff',
            Actor::DEVICE => 'device',
            Actor::CUSTOMER => 'customer',
            default => 'system',
        };
    }

    private function firstImageId(int $variantId): ?int
    {
        $row = $this->db->first(
            sprintf(
                'SELECT pm.attachment_id
                   FROM %sproduct_media pm
                   JOIN %svariants v ON v.product_id = pm.product_id
                  WHERE v.id = ?
                  ORDER BY pm.sort_order ASC, pm.id ASC LIMIT 1',
                $this->db->prefix(),
                $this->db->prefix()
            ),
            [$variantId]
        );

        return $row === null ? null : (int) $row['attachment_id'];
    }
}
