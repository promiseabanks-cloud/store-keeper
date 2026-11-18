<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Pos;

use StoreKeeper\Commerce\Catalog\CatalogRepository;
use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Audit\AuditLogger;
use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;
use StoreKeeper\Commerce\Core\Exception\NotFound;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Core\Uuid;
use StoreKeeper\Commerce\Inventory\InventoryService;
use StoreKeeper\Commerce\Orders\OrderRepository;
use StoreKeeper\Commerce\Orders\OrderService;
use StoreKeeper\Commerce\Pricing\PricingService;

/**
 * Counter sales, parked sales, counter returns and cash-up (§15.1).
 *
 * A counter sale is created through OrderService with `handed_over_now`, so the
 * goods leave the shelf in the same transaction the order is written (§9.9).
 * Prices quoted on an offline device are honoured (§15.3 rule 4); a difference
 * from the current resolved price is attached to the order as a note.
 */
final class CounterService
{
    private const PAYMENT_METHODS = ['cash', 'pos_terminal', 'bank_transfer', 'pay_on_delivery'];

    public function __construct(
        private readonly Connection $db,
        private readonly OrderService $orders,
        private readonly OrderRepository $orderRepository,
        private readonly SyncRepository $sync,
        private readonly CatalogRepository $catalog,
        private readonly PricingService $pricing,
        private readonly InventoryService $inventory,
        private readonly ChangeLog $changeLog,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly int $priceChangeThresholdBps = 500,
    ) {
    }

    /**
     * @param array<string,mixed> $input client_uuid, lines, payments, customer_id, discount_kobo, note
     * @param array<string,mixed> $device
     * @return array<string,mixed>
     */
    public function sale(array $input, array $device, Actor $actor): array
    {
        $clientUuid = trim((string) ($input['client_uuid'] ?? ''));
        if ($clientUuid === '' || mb_strlen($clientUuid) > 64) {
            throw new ValidationFailed('Every counter sale needs a client UUID.', ['client_uuid' => 'Required, at most 64 characters.']);
        }

        $existing = $this->sync->orderByClientUuid($clientUuid);
        if ($existing !== null) {
            return [
                'order' => $existing,
                'items' => $this->orderRepository->items((int) $existing['id']),
                'warnings' => [],
                'duplicate' => true,
                'stock_levels' => $this->levelsForOrder((int) $existing['id'], (int) $device['location_id']),
            ];
        }

        $lines = $input['lines'] ?? [];
        if (!is_array($lines) || $lines === []) {
            throw new ValidationFailed('A counter sale needs at least one line.', ['lines' => 'Required.']);
        }

        $customerId = isset($input['customer_id']) && (int) $input['customer_id'] > 0 ? (int) $input['customer_id'] : null;
        $customer = $customerId === null ? null : $this->orderRepository->customer($customerId);
        if ($customerId !== null && $customer === null) {
            throw new NotFound('customer', $customerId);
        }

        $prepared = [];
        $warnings = [];
        $subtotal = 0;

        foreach ($lines as $line) {
            $row = $this->prepareLine($line, $customer, $warnings);
            $subtotal += $row['unit_price_kobo'] * $row['qty'];
            $prepared[] = $row;
        }

        $discount = max(0, (int) ($input['discount_kobo'] ?? 0));
        if ($discount > $subtotal) {
            throw new ValidationFailed('The discount cannot exceed the subtotal.', ['discount_kobo' => 'Too large.']);
        }

        $total = $subtotal - $discount;
        $payments = $this->preparePayments($input['payments'] ?? [], $total);

        $note = trim((string) ($input['note'] ?? ''));
        if ($warnings !== []) {
            $note = trim($note . ' | ' . implode('; ', array_unique($warnings)));
        }

        $result = $this->orders->create([
            'channel' => 'counter',
            'idempotency_key' => 'pos:' . $clientUuid,
            'handed_over_now' => true,
            'allow_manual_price' => true,
            'payment_method' => 'other',
            'location_id' => (int) $device['location_id'],
            'device_id' => (int) $device['id'],
            'customer_id' => $customerId,
            'discount_kobo' => $discount,
            'lines' => array_map(static fn (array $row): array => [
                'variant_id' => $row['variant_id'],
                'qty' => $row['qty'],
                'pack_unit_id' => $row['pack_unit_id'],
                'unit_price_kobo' => $row['unit_price_kobo'],
            ], $prepared),
            'staff_note' => $note === '' ? null : $note,
            'client_created_at' => $input['client_created_at'] ?? null,
        ], $actor);

        $order = $result['order'];
        $orderId = (int) $order['id'];

        $this->db->insert($this->db->prefix() . 'pos_sales', [
            'order_id' => $orderId,
            'device_id' => (int) $device['id'],
            'cashier_user_id' => (int) ($input['cashier_user_id'] ?? ($actor->id ?? 0)),
            'client_uuid' => $clientUuid,
            'created_at' => $this->clock->nowString(),
        ]);

        foreach ($payments as $payment) {
            $this->orders->recordPayment($orderId, [
                'method' => $payment['method'],
                'amount_kobo' => $payment['amount_kobo'],
                'reference' => $payment['reference'] ?? null,
            ], $actor);
        }

        $order = $this->orderRepository->order($orderId) ?? $order;

        foreach ($prepared as $row) {
            $level = $this->inventory->level((int) $row['variant_id'], (int) $device['location_id']);
            if ($level !== null && (int) $level['on_hand'] < 0) {
                $warnings[] = 'oversell:' . $row['sku'];
            }
        }

        $this->audit->log($actor, 'pos.sale', 'order', $orderId, null, [
            'device_id' => (int) $device['id'],
            'cashier_user_id' => (int) ($input['cashier_user_id'] ?? ($actor->id ?? 0)),
            'total_kobo' => (int) $order['total_kobo'],
            'payments' => $payments,
        ]);

        return [
            'order' => $order,
            'items' => $this->orderRepository->items($orderId),
            'warnings' => array_values(array_unique($warnings)),
            'duplicate' => (bool) $result['replayed'],
            'stock_levels' => $this->levelsForOrder($orderId, (int) $device['location_id']),
        ];
    }

    /**
     * Park a sale: a draft order with no stock movement until it is resumed and
     * paid (§15.1).
     *
     * @param array<string,mixed> $input client_uuid, lines, customer_id, discount_kobo
     * @param array<string,mixed> $device
     * @return array<string,mixed>
     */
    public function hold(array $input, array $device, Actor $actor): array
    {
        $clientUuid = trim((string) ($input['client_uuid'] ?? ''));
        if ($clientUuid === '') {
            throw new ValidationFailed('A parked sale needs a client UUID.', ['client_uuid' => 'Required.']);
        }

        $existing = $this->sync->orderByClientUuid($clientUuid);
        if ($existing !== null) {
            return ['order' => $existing, 'items' => $this->orderRepository->items((int) $existing['id']), 'duplicate' => true];
        }

        $customerId = isset($input['customer_id']) && (int) $input['customer_id'] > 0 ? (int) $input['customer_id'] : null;
        $customer = $customerId === null ? null : $this->orderRepository->customer($customerId);
        $lines = $input['lines'] ?? [];

        if (!is_array($lines) || $lines === []) {
            throw new ValidationFailed('A parked sale needs at least one line.', ['lines' => 'Required.']);
        }

        $warnings = [];
        $prepared = array_map(fn (array $line): array => $this->prepareLine($line, $customer, $warnings), $lines);

        $result = $this->orders->create([
            'channel' => 'counter',
            'idempotency_key' => 'pos:' . $clientUuid,
            'draft' => true,
            'allow_manual_price' => true,
            'location_id' => (int) $device['location_id'],
            'device_id' => (int) $device['id'],
            'customer_id' => $customerId,
            'discount_kobo' => max(0, (int) ($input['discount_kobo'] ?? 0)),
            'lines' => array_map(static fn (array $row): array => [
                'variant_id' => $row['variant_id'],
                'qty' => $row['qty'],
                'pack_unit_id' => $row['pack_unit_id'],
                'unit_price_kobo' => $row['unit_price_kobo'],
            ], $prepared),
            'staff_note' => trim((string) ($input['note'] ?? '')) ?: null,
        ], $actor);

        $this->db->insert($this->db->prefix() . 'pos_sales', [
            'order_id' => (int) $result['order']['id'],
            'device_id' => (int) $device['id'],
            'cashier_user_id' => (int) ($input['cashier_user_id'] ?? ($actor->id ?? 0)),
            'client_uuid' => $clientUuid,
            'created_at' => $this->clock->nowString(),
        ]);

        return [
            'order' => $result['order'],
            'items' => $result['items'],
            'warnings' => array_values(array_unique($warnings)),
            'duplicate' => (bool) $result['replayed'],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function held(int $deviceId): array
    {
        $orders = $this->sync->heldByDevice($deviceId);
        $items = $this->sync->itemsForOrders(array_map(static fn (array $row): int => (int) $row['id'], $orders));
        $byOrder = [];
        foreach ($items as $item) {
            $byOrder[(int) $item['order_id']][] = $item;
        }

        return array_map(static function (array $order) use ($byOrder): array {
            $order['items'] = $byOrder[(int) $order['id']] ?? [];

            return $order;
        }, $orders);
    }

    /** @param array<string,mixed> $device */
    public function cancelHeld(int $orderId, array $device, Actor $actor): array
    {
        $row = $this->db->first(
            sprintf('SELECT * FROM %spos_sales WHERE order_id = ? AND device_id = ?', $this->db->prefix()),
            [$orderId, (int) $device['id']]
        );

        if ($row === null) {
            throw new NotFound('held sale', $orderId);
        }

        return $this->orders->cancel($orderId, 'Held sale discarded', $actor);
    }

    /**
     * Record a cash-up. The expected figure is opening float plus cash taken on
     * this device by this cashier that day, less cash refunded (§15.1).
     *
     * @param array<string,mixed> $input cashier_user_id, business_date, opening_float_kobo, counted_cash_kobo, note
     * @param array<string,mixed> $device
     * @return array<string,mixed>
     */
    public function cashUp(array $input, array $device, Actor $actor): array
    {
        $cashierId = (int) ($input['cashier_user_id'] ?? ($actor->id ?? 0));
        if ($cashierId <= 0) {
            throw new ValidationFailed('A cash-up needs a cashier.', ['cashier_user_id' => 'Required.']);
        }

        // Business date defaults to the store clock's day (UTC storage, §7.3).
        $date = (string) ($input['business_date'] ?? $this->clock->now()->format('Y-m-d'));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new ValidationFailed('Use a business date of YYYY-MM-DD.', ['business_date' => 'Invalid date.']);
        }

        $opening = max(0, (int) ($input['opening_float_kobo'] ?? 0));
        $counted = (int) ($input['counted_cash_kobo'] ?? 0);
        if ($counted < 0) {
            throw new ValidationFailed('Counted cash cannot be negative.', ['counted_cash_kobo' => 'Must be zero or more.']);
        }

        $cash = $this->sync->cashForDay((int) $device['id'], $cashierId, $date);
        $expected = $opening + $cash['cash_in_kobo'] - $cash['cash_refunded_kobo'];
        $difference = $counted - $expected;

        $row = $this->sync->cashUpFor((int) $device['id'], $cashierId, $date);
        $data = [
            'opening_float_kobo' => $opening,
            'expected_cash_kobo' => $expected,
            'counted_cash_kobo' => $counted,
            'difference_kobo' => $difference,
            'note' => trim((string) ($input['note'] ?? '')) ?: null,
            'created_at' => $this->clock->nowString(),
        ];

        if ($row === null) {
            $id = $this->sync->createCashUp([
                'uuid' => Uuid::v4(),
                'device_id' => (int) $device['id'],
                'cashier_user_id' => $cashierId,
                'business_date' => $date,
                ...$data,
            ]);
        } else {
            $id = (int) $row['id'];
            unset($data['created_at']);
            $this->sync->updateCashUp($id, $data);
        }

        $cashUp = $this->sync->cashUpById($id) ?? [];

        $this->audit->log($actor, 'pos.cash_up', 'cash_up', $id, null, [
            'device_id' => (int) $device['id'],
            'difference_kobo' => $difference,
        ]);

        return $cashUp;
    }

    /**
     * Return goods at the counter. Records timestamps, money and (optionally)
     * restocks the goods; a repeated client UUID returns the first result.
     *
     * @param array<string,mixed> $input client_uuid, order_uuid|order_number, lines, restock, reason, method
     * @param array<string,mixed> $device
     * @return array<string,mixed>
     */
    public function counterReturn(array $input, array $device, Actor $actor): array
    {
        $clientUuid = trim((string) ($input['client_uuid'] ?? ''));
        if ($clientUuid === '') {
            throw new ValidationFailed('A counter return needs a client UUID.', ['client_uuid' => 'Required.']);
        }

        $seen = $this->db->first(
            sprintf('SELECT * FROM %spos_returns WHERE client_uuid = ?', $this->db->prefix()),
            [$clientUuid]
        );
        if ($seen !== null) {
            $orderId = (int) $seen['order_id'];

            return [
                'order' => $this->orderRepository->order($orderId),
                'refund' => $this->db->first(
                    sprintf('SELECT * FROM %srefunds WHERE id = ?', $this->db->prefix()),
                    [(int) $seen['refund_id']]
                ) ?? [],
                'duplicate' => true,
            ];
        }

        $orderId = isset($input['order_id']) && (int) $input['order_id'] > 0 ? (int) $input['order_id'] : 0;
        if ($orderId === 0 && !empty($input['order_number'])) {
            $order = $this->sync->orderByNumber((string) $input['order_number']);
            $orderId = $order === null ? 0 : (int) $order['id'];
        } elseif ($orderId === 0 && !empty($input['order_uuid'])) {
            $order = $this->orderRepository->orderByUuid((string) $input['order_uuid']);
            $orderId = $order === null ? 0 : (int) $order['id'];
        }

        $order = $orderId > 0 ? $this->orderRepository->order($orderId) : null;
        if ($order === null) {
            throw new NotFound('order', (string) ($input['order_number'] ?? $input['order_uuid'] ?? ''));
        }

        $lines = $input['lines'] ?? [];
        if (!is_array($lines) || $lines === []) {
            throw new ValidationFailed('A counter return needs at least one line.', ['lines' => 'Required.']);
        }

        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            throw new ValidationFailed('Say why the goods came back.', ['reason' => 'Required.']);
        }

        $method = (string) ($input['method'] ?? 'cash');
        if (!in_array($method, ['cash', 'bank_transfer', 'pos_terminal'], true)) {
            throw new ValidationFailed('Unknown refund method.', ['method' => 'cash, bank_transfer or pos_terminal.']);
        }

        $restock = !array_key_exists('restock', $input) || !empty($input['restock']);
        $items = [];
        foreach ($this->orderRepository->items($orderId) as $item) {
            $items[(int) $item['id']] = $item;
        }

        $prepared = [];
        $amount = 0;
        $totalPieces = 0;

        foreach ($lines as $line) {
            $itemId = (int) ($line['order_item_id'] ?? 0);
            if (!isset($items[$itemId])) {
                throw new ValidationFailed('That line is not on this order.', ['order_item_id' => 'Unknown line.']);
            }

            $item = $items[$itemId];
            $already = (int) $this->db->scalar(
                sprintf(
                    'SELECT COALESCE(SUM(ri.qty_pieces), 0) FROM %srefund_items ri
                       JOIN %srefunds r ON r.id = ri.refund_id
                      WHERE r.order_id = ? AND ri.order_item_id = ?',
                    $this->db->prefix(),
                    $this->db->prefix()
                ),
                [$orderId, $itemId]
            );

            $qty = (int) ($line['qty_pieces'] ?? ($line['qty'] ?? 0) * (int) $item['pack_pieces']);
            if ($qty <= 0) {
                throw new ValidationFailed('Every return line needs a quantity.', ['qty_pieces' => 'Must be 1 or more.']);
            }

            $available = (int) $item['qty_pieces'] - $already;
            if ($qty > $available) {
                throw new ValidationFailed('A return cannot exceed what is left on the line.', [
                    'qty_pieces' => sprintf('Only %d pieces can still be returned.', $available),
                ]);
            }

            $lineAmount = isset($line['amount_kobo'])
                ? max(0, (int) $line['amount_kobo'])
                : intdiv((int) $item['line_total_kobo'] * $qty, max(1, (int) $item['qty_pieces']));

            $prepared[] = ['item' => $item, 'qty_pieces' => $qty, 'amount_kobo' => $lineAmount];
            $amount += $lineAmount;
            $totalPieces += $qty;
        }

        $now = $this->clock->nowString();

        $result = $this->db->transaction(function () use (
            $orderId,
            $prepared,
            $clientUuid,
            $amount,
            $method,
            $reason,
            $restock,
            $actor,
            $now,
            $device
        ): array {
            $refundId = $this->db->insert($this->db->prefix() . 'refunds', [
                'uuid' => Uuid::v4(),
                'order_id' => $orderId,
                'payment_id' => null,
                'amount_kobo' => $amount,
                'method' => $method,
                'reason' => $reason,
                'restock' => $restock ? 1 : 0,
                'gateway_reference' => null,
                'created_by' => (int) ($actor->id ?? 0),
                'created_at' => $now,
            ]);

            foreach ($prepared as $line) {
                $item = $line['item'];
                $this->db->insert($this->db->prefix() . 'refund_items', [
                    'refund_id' => $refundId,
                    'order_item_id' => (int) $item['id'],
                    'qty_pieces' => (int) $line['qty_pieces'],
                    'amount_kobo' => (int) $line['amount_kobo'],
                ]);

                if ($restock) {
                    $this->inventory->returnStock(
                        (int) $item['variant_id'],
                        (int) $item['location_id'],
                        (int) $line['qty_pieces'],
                        $actor,
                        $orderId,
                        'POS return: ' . $reason
                    );
                }

                $this->orderRepository->updateItem((int) $item['id'], [
                    'qty_fulfilled_pieces' => max(0, (int) $item['qty_fulfilled_pieces'] - (int) $line['qty_pieces']),
                ]);
            }

            $this->db->insert($this->db->prefix() . 'pos_returns', [
                'client_uuid' => $clientUuid,
                'refund_id' => $refundId,
                'order_id' => $orderId,
                'created_at' => $now,
            ]);

            return ['refund_id' => $refundId];
        });

        $updated = $this->orders->refreshStatuses($orderId, $actor);

        $this->audit->log($actor, 'pos.return', 'order', $orderId, null, [
            'refund_id' => $result['refund_id'],
            'amount_kobo' => $amount,
            'pieces' => $totalPieces,
            'restock' => $restock,
            'device_id' => (int) $device['id'],
        ]);

        return [
            'order' => $updated,
            'refund' => $this->db->first(
                sprintf('SELECT * FROM %srefunds WHERE id = ?', $this->db->prefix()),
                [$result['refund_id']]
            ) ?? [],
            'duplicate' => false,
        ];
    }

    /**
     * @param array<string,mixed> $line
     * @param array<string,mixed>|null $customer
     * @param list<string> $warnings
     * @return array<string,mixed>
     */
    private function prepareLine(array $line, ?array $customer, array &$warnings): array
    {
        $variantId = (int) ($line['variant_id'] ?? 0);
        if ($variantId === 0 && !empty($line['variant_uuid'])) {
            $variantId = (int) $this->db->scalar(
                sprintf('SELECT id FROM %svariants WHERE uuid = ?', $this->db->prefix()),
                [(string) $line['variant_uuid']]
            );
        }

        $variant = $this->catalog->variant($variantId);
        if ($variant === null) {
            throw new NotFound('variant', $variantId);
        }

        $qty = (int) ($line['qty'] ?? 0);
        if ($qty < 1) {
            throw new ValidationFailed('Every line needs a quantity of at least 1.', ['qty' => 'Must be 1 or more.']);
        }

        $packUnitId = isset($line['pack_unit_id']) && (int) $line['pack_unit_id'] > 0 ? (int) $line['pack_unit_id'] : null;

        $quoted = isset($line['unit_price_kobo']) ? (int) $line['unit_price_kobo'] : null;
        if ($quoted !== null && $quoted < 0) {
            throw new ValidationFailed('A price cannot be negative.', ['unit_price_kobo' => 'Must be zero or more.']);
        }

        if ($quoted === null) {
            $resolved = $this->pricing->resolve($variantId, $packUnitId, $qty, $customer);
            $quoted = $resolved->unitAmount->minor();
        } elseif ($customer !== null) {
            try {
                $current = $this->pricing->resolve($variantId, $packUnitId, $qty, $customer)->unitAmount->minor();
                if ($current > 0 && abs($quoted - $current) * 10000 > $current * max(1, $this->priceChangeThresholdBps)) {
                    $warnings[] = sprintf('price_changed_offline:%s', (string) $variant['sku']);
                }
            } catch (\Throwable) {
                // No current price to compare with; the quoted price stands.
            }
        }

        return [
            'variant_id' => $variantId,
            'sku' => (string) $variant['sku'],
            'qty' => $qty,
            'pack_unit_id' => $packUnitId,
            'unit_price_kobo' => $quoted,
        ];
    }

    /**
     * @param mixed $payments
     * @return list<array{method:string,amount_kobo:int,reference:?string}>
     */
    private function preparePayments(mixed $payments, int $total): array
    {
        if (!is_array($payments)) {
            throw new ValidationFailed('Payments must be a list.', ['payments' => 'Invalid.']);
        }

        $prepared = [];
        $sum = 0;

        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                throw new ValidationFailed('A payment needs a method and an amount.', ['payments' => 'Invalid line.']);
            }

            $method = (string) ($payment['method'] ?? 'cash');
            if (!in_array($method, self::PAYMENT_METHODS, true)) {
                throw new ValidationFailed('Unknown payment method.', ['method' => implode(', ', self::PAYMENT_METHODS)]);
            }

            $amount = (int) ($payment['amount_kobo'] ?? 0);
            if ($amount <= 0) {
                throw new ValidationFailed('A payment must be more than zero.', ['amount_kobo' => 'Too small.']);
            }

            $sum += $amount;
            $prepared[] = [
                'method' => $method,
                'amount_kobo' => $amount,
                'reference' => trim((string) ($payment['reference'] ?? '')) ?: null,
            ];
        }

        if ($sum > $total) {
            throw new ValidationFailed('Payments add up to more than the sale.', [
                'payments' => sprintf('Total is %d kobo.', $total),
            ]);
        }

        return $prepared;
    }

    /** @return list<array<string,mixed>> */
    private function levelsForOrder(int $orderId, int $locationId): array
    {
        $variantIds = array_map(
            static fn (array $item): int => (int) $item['variant_id'],
            $this->orderRepository->items($orderId)
        );

        if ($variantIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($variantIds), '?'));
        $params = array_merge(array_map('intval', $variantIds), [$locationId]);

        return $this->db->select(
            sprintf(
                'SELECT variant_id, on_hand, reserved FROM %sstock_levels
                  WHERE variant_id IN (%s) AND location_id = ?',
                $this->db->prefix(),
                $placeholders
            ),
            $params
        );
    }
}
