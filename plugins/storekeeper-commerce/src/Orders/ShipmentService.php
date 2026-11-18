<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Orders;

use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Audit\AuditLogger;
use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;
use StoreKeeper\Commerce\Core\Exception\NotFound;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Core\Hooks\Hooks;
use StoreKeeper\Commerce\Core\Uuid;
use StoreKeeper\Commerce\Inventory\InventoryService;
use StoreKeeper\Commerce\Orders\Exception\InvalidTransition;

/**
 * Parcels for an order (§14.1).
 *
 * One order can leave in several parcels. Packing commits that parcel's stock
 * reservations and counts the pieces towards the order's fulfilment; delivering
 * a pay-on-delivery parcel records the cash collected; returning a delivered or
 * failed parcel can put the goods back on the shelf. Every step writes a
 * `shipment_events` row, so the customer's tracking page is a record of what
 * actually happened, not a status guessed from the order.
 */
final class ShipmentService
{
    /** @var list<string> */
    private const DETAIL_FIELDS = [
        'carrier',
        'waybill_number',
        'rider_name',
        'rider_phone',
        'method_name',
        'photo_attachment_id',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly ShipmentRepository $shipments,
        private readonly OrderRepository $orders,
        private readonly OrderService $orderService,
        private readonly InventoryService $inventory,
        private readonly Hooks $hooks,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Create a parcel from an order (§14.1). Lines default to everything not
     * yet packed or allocated to another open parcel.
     *
     * @param list<array{order_item_id:int,qty_pieces:int}> $lines
     * @param array<string,mixed> $details carrier, waybill_number, rider_name, rider_phone, method_name, note
     * @return array<string,mixed>
     */
    public function create(int $orderId, array $lines, Actor $actor, array $details = []): array
    {
        $order = $this->orders->order($orderId);

        if ($order === null) {
            throw new NotFound('order', $orderId);
        }

        if ((string) $order['status'] === OrderStatus::CANCELLED) {
            throw new ValidationFailed('A cancelled order cannot be packed.', ['order' => 'Cancelled.']);
        }

        $items = [];

        foreach ($this->orders->items($orderId) as $item) {
            $items[(int) $item['id']] = $item;
        }

        if ($lines === []) {
            foreach ($items as $item) {
                $remaining = $this->remaining((int) $item['id'], $item);
                if ($remaining > 0) {
                    $lines[] = ['order_item_id' => (int) $item['id'], 'qty_pieces' => $remaining];
                }
            }
        }

        if ($lines === []) {
            throw new ValidationFailed('Everything on this order has already been packed.', ['lines' => 'Nothing left.']);
        }

        $prepared = [];

        foreach ($lines as $line) {
            $orderItemId = (int) ($line['order_item_id'] ?? 0);
            $qty = (int) ($line['qty_pieces'] ?? 0);

            if (!isset($items[$orderItemId])) {
                throw new ValidationFailed('That line does not belong to this order.', ['order_item_id' => 'Unknown line.']);
            }

            if ($qty < 1) {
                throw new ValidationFailed('Every parcel line needs at least one piece.', ['qty_pieces' => 'Must be 1 or more.']);
            }

            $remaining = $this->remaining($orderItemId, $items[$orderItemId]);

            if ($qty > $remaining) {
                throw new ValidationFailed(
                    'This parcel is larger than what is left to pack.',
                    ['qty_pieces' => sprintf('%d piece(s) remain for that line.', $remaining)]
                );
            }

            $prepared[] = ['order_item_id' => $orderItemId, 'qty_pieces' => $qty];
        }

        $address = json_decode((string) ($order['shipping_address'] ?? ''), true);
        $address = is_array($address) ? $address : [];
        $now = $this->clock->nowString();

        $shipmentId = $this->db->transaction(function () use ($order, $prepared, $details, $address, $now, $actor): int {
            $shipmentId = $this->shipments->create([
                'uuid' => Uuid::v4(),
                'order_id' => (int) $order['id'],
                'status' => ShipmentStatus::PENDING,
                'method_name' => $this->text($details['method_name'] ?? ($address['shipping_label'] ?? null)),
                'carrier' => $this->text($details['carrier'] ?? null),
                'waybill_number' => $this->text($details['waybill_number'] ?? null),
                'rider_name' => $this->text($details['rider_name'] ?? null),
                'rider_phone' => $this->text($details['rider_phone'] ?? null),
                'photo_attachment_id' => isset($details['photo_attachment_id']) ? (int) $details['photo_attachment_id'] : null,
                'location_id' => (int) ($details['location_id'] ?? $order['location_id']),
                'packed_at' => null,
                'dispatched_at' => null,
                'delivered_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($prepared as $line) {
                $this->shipments->addItem($shipmentId, $line['order_item_id'], $line['qty_pieces']);
            }

            $this->shipments->addEvent(
                $shipmentId,
                ShipmentStatus::PENDING,
                $this->text($details['note'] ?? null) ?? 'Parcel created.',
                $this->actorType($actor),
                $actor->id
            );

            return $shipmentId;
        });

        $shipment = $this->find($shipmentId) ?? [];

        $this->audit->log($actor, 'shipment.created', 'shipment', $shipmentId, null, [
            'order_id' => (int) $order['id'],
            'lines' => $prepared,
        ]);

        return $shipment;
    }

    /**
     * Move a parcel along its lifecycle and/or record dispatch details.
     *
     * @param array<string,mixed> $changes status, carrier, waybill_number, rider_name, rider_phone, note, restock, collected_kobo, collection_method
     * @return array<string,mixed>
     */
    public function update(int $shipmentId, array $changes, Actor $actor): array
    {
        $shipment = $this->shipments->shipment($shipmentId);

        if ($shipment === null) {
            throw new NotFound('shipment', $shipmentId);
        }

        $order = $this->orders->order((int) $shipment['order_id']);

        if ($order === null) {
            throw new NotFound('order', (int) $shipment['order_id']);
        }

        $from = (string) $shipment['status'];
        $to = isset($changes['status']) ? (string) $changes['status'] : $from;

        if ($to !== $from) {
            ShipmentStatus::assert($from, $to);
        }

        $note = $this->text($changes['note'] ?? null);
        $details = $this->details($changes);

        if ($to === ShipmentStatus::DISPATCHED) {
            $carrier = $details['carrier'] ?? $this->text($shipment['carrier']);
            $method = $details['method_name'] ?? $this->text($shipment['method_name']);

            if ($carrier === null && $method === null) {
                throw new ValidationFailed(
                    'Say who is carrying the parcel.',
                    ['carrier' => 'A carrier or delivery method is required.']
                );
            }
        }

        if ($to === ShipmentStatus::FAILED_DELIVERY && $note === null) {
            throw new ValidationFailed(
                'Say why the delivery failed.',
                ['note' => 'A short note is required.']
            );
        }

        $items = $this->shipments->items($shipmentId);
        $now = $this->clock->nowString();
        $restock = !empty($changes['restock']);
        $collectedKobo = max(0, (int) ($changes['collected_kobo'] ?? 0));

        $this->db->transaction(function () use ($shipment, $order, $shipmentId, $items, $from, $to, $details, $note, $actor, $now, $restock): void {
            $orderId = (int) $order['id'];

            if ($to === ShipmentStatus::PACKED && $from === ShipmentStatus::PENDING) {
                foreach ($items as $item) {
                    $orderItemId = (int) $item['order_item_id'];
                    $qty = (int) $item['qty_pieces'];
                    $orderItem = $this->orders->items($orderId);
                    $line = null;

                    foreach ($orderItem as $candidate) {
                        if ((int) $candidate['id'] === $orderItemId) {
                            $line = $candidate;
                            break;
                        }
                    }

                    if ($line === null) {
                        throw new NotFound('order line', $orderItemId);
                    }

                    $this->inventory->commit(
                        (int) $line['variant_id'],
                        (int) $line['location_id'],
                        $qty,
                        $actor,
                        $orderId,
                        $orderItemId
                    );

                    $this->orders->updateItem($orderItemId, [
                        'qty_fulfilled_pieces' => (int) $line['qty_fulfilled_pieces'] + $qty,
                    ]);
                }

                $details['packed_at'] = $now;
            }

            if ($to === ShipmentStatus::DISPATCHED) {
                $details['dispatched_at'] = $now;
            }

            if ($to === ShipmentStatus::DELIVERED) {
                $details['delivered_at'] = $now;
            }

            if ($to === ShipmentStatus::RETURNED) {
                $this->returnLines($shipment, $order, $items, $restock, $actor);

                foreach ($items as $item) {
                    $orderItemId = (int) $item['order_item_id'];
                    $current = 0;

                    foreach ($this->orders->items($orderId) as $candidate) {
                        if ((int) $candidate['id'] === $orderItemId) {
                            $current = (int) $candidate['qty_fulfilled_pieces'];
                            break;
                        }
                    }

                    $this->orders->updateItem($orderItemId, [
                        'qty_fulfilled_pieces' => max(0, $current - (int) $item['qty_pieces']),
                    ]);
                }
            }

            $details['status'] = $to;
            $this->shipments->update($shipmentId, $details);

            $this->shipments->addEvent(
                $shipmentId,
                $to,
                $note,
                $this->actorType($actor),
                $actor->id
            );
        });

        $updated = $this->orderService->refreshStatuses((int) $order['id'], $actor);

        $collected = null;

        if ($to === ShipmentStatus::DELIVERED && $collectedKobo > 0) {
            $collected = $this->orderService->recordPayment((int) $order['id'], [
                'amount_kobo' => $collectedKobo,
                'method' => (string) ($changes['collection_method'] ?? 'pay_on_delivery'),
                'note' => 'Collected on delivery',
            ], $actor);
        }

        $fresh = $this->find($shipmentId) ?? [];

        $this->audit->log($actor, 'shipment.status_changed', 'shipment', $shipmentId, [
            'status' => $from,
        ], [
            'status' => $to,
            'order_number' => $order['number'] ?? null,
        ]);

        $this->hooks->do('shipment/status_changed', [
            'order' => $collected ?? $updated,
            'shipment' => $fresh,
            'from' => $from,
            'to' => $to,
        ]);

        return $fresh;
    }

    /**
     * Move several parcels to the same status in one go (§14.2 bulk buttons).
     *
     * @param list<int> $shipmentIds
     * @return array{updated:list<int>,failed:array<int,string>}
     */
    public function bulkStatus(array $shipmentIds, string $status, Actor $actor): array
    {
        $updated = [];
        $failed = [];

        foreach ($shipmentIds as $shipmentId) {
            try {
                $this->update((int) $shipmentId, ['status' => $status], $actor);
                $updated[] = (int) $shipmentId;
            } catch (\Throwable $error) {
                $failed[(int) $shipmentId] = $error->getMessage();
            }
        }

        return ['updated' => $updated, 'failed' => $failed];
    }

    /** @return array<string,mixed>|null */
    public function find(int $shipmentId): ?array
    {
        $shipment = $this->shipments->shipment($shipmentId);

        if ($shipment === null) {
            return null;
        }

        $shipment['items'] = $this->shipments->items($shipmentId);
        $shipment['events'] = $this->shipments->events($shipmentId);
        $shipment['order'] = $this->orderSummary((int) $shipment['order_id']);

        return $shipment;
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        $shipment = $this->shipments->shipmentByUuid($uuid);

        return $shipment === null ? null : $this->find((int) $shipment['id']);
    }

    /** @return list<array<string,mixed>> */
    public function forOrder(int $orderId): array
    {
        return $this->shipments->forOrderDetailed($orderId);
    }

    /**
     * Parcels across orders for the staff screens (§14.2). Defaults to the
     * "to pack" list: everything still `pending`, oldest first is not useful
     * here, so the repository returns newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function listing(?string $status = null, int $limit = 100): array
    {
        if ($status !== null && $status !== '' && !in_array($status, ShipmentStatus::all(), true)) {
            throw new ValidationFailed(
                'Unknown parcel status.',
                ['status' => implode(', ', ShipmentStatus::all())]
            );
        }

        $rows = $this->shipments->listWithOrder($status, min(200, max(1, $limit)));
        $out = [];

        foreach ($rows as $row) {
            $address = json_decode((string) ($row['order_shipping_address'] ?? ''), true);
            $address = is_array($address) ? $address : [];

            $out[] = [
                'id' => (int) $row['id'],
                'uuid' => (string) $row['uuid'],
                'status' => (string) $row['status'],
                'status_label' => ShipmentStatus::label((string) $row['status']),
                'method_name' => $row['method_name'] === null ? null : (string) $row['method_name'],
                'carrier' => $row['carrier'] === null ? null : (string) $row['carrier'],
                'waybill_number' => $row['waybill_number'] === null ? null : (string) $row['waybill_number'],
                'rider_name' => $row['rider_name'] === null ? null : (string) $row['rider_name'],
                'rider_phone' => $row['rider_phone'] === null ? null : (string) $row['rider_phone'],
                'pieces' => (int) $row['pieces'],
                'created_at' => (string) $row['created_at'],
                'packed_at' => $row['packed_at'] === null ? null : (string) $row['packed_at'],
                'dispatched_at' => $row['dispatched_at'] === null ? null : (string) $row['dispatched_at'],
                'delivered_at' => $row['delivered_at'] === null ? null : (string) $row['delivered_at'],
                'order' => [
                    'uuid' => (string) $row['order_uuid'],
                    'number' => (string) $row['order_number'],
                    'payment_status' => (string) $row['order_payment_status'],
                    'fulfilment_status' => (string) $row['order_fulfilment_status'],
                    'recipient_name' => isset($address['recipient_name']) ? (string) $address['recipient_name'] : null,
                    'phone' => isset($address['phone_e164']) ? (string) $address['phone_e164'] : null,
                    'email' => isset($address['email']) ? (string) $address['email'] : null,
                    'delivery_method' => isset($address['method']) ? (string) $address['method'] : null,
                    'line1' => isset($address['line1']) ? (string) $address['line1'] : null,
                    'line2' => isset($address['line2']) ? (string) $address['line2'] : null,
                    'landmark' => isset($address['landmark']) ? (string) $address['landmark'] : null,
                    'city' => isset($address['city']) ? (string) $address['city'] : null,
                    'lga' => isset($address['lga']) ? (string) $address['lga'] : null,
                    'state' => isset($address['state']) ? (string) $address['state'] : null,
                    'shipping_label' => isset($address['shipping_label']) ? (string) $address['shipping_label'] : null,
                ],
            ];
        }

        return $out;
    }

    /** The "to pack" list: parcels nobody has packed yet. @return list<array<string,mixed>> */
    public function toPack(int $limit = 100): array
    {
        return $this->listing(ShipmentStatus::PENDING, $limit);
    }

    /** @return array<string,mixed>|null */
    private function orderSummary(int $orderId): ?array
    {
        $order = $this->orders->order($orderId);

        if ($order === null) {
            return null;
        }

        $address = json_decode((string) ($order['shipping_address'] ?? ''), true);

        return [
            'id' => (int) $order['id'],
            'uuid' => (string) $order['uuid'],
            'number' => (string) $order['number'],
            'status' => (string) $order['status'],
            'payment_status' => (string) $order['payment_status'],
            'fulfilment_status' => (string) $order['fulfilment_status'],
            'total_kobo' => (int) $order['total_kobo'],
            'shipping_address' => is_array($address) ? $address : [],
            'created_at' => (string) $order['created_at'],
        ];
    }

    /**
     * What is left of an order line after fulfilled pieces and open parcels.
     *
     * @param array<string,mixed> $item
     */
    public function remaining(int $orderItemId, array $item): int
    {
        $allocated = $this->shipments->allocatedPieces($orderItemId);

        return max(0, (int) $item['qty_pieces'] - (int) $item['qty_fulfilled_pieces'] - $allocated);
    }

    /**
     * @param array<string,mixed> $shipment
     * @param array<string,mixed> $order
     * @param list<array<string,mixed>> $items
     */
    private function returnLines(array $shipment, array $order, array $items, bool $restock, Actor $actor): void
    {
        if (!$restock) {
            return;
        }

        $orderLines = [];

        foreach ($this->orders->items((int) $order['id']) as $line) {
            $orderLines[(int) $line['id']] = $line;
        }

        foreach ($items as $item) {
            $line = $orderLines[(int) $item['order_item_id']] ?? null;

            if ($line === null) {
                continue;
            }

            $this->inventory->returnStock(
                (int) $line['variant_id'],
                (int) $line['location_id'],
                (int) $item['qty_pieces'],
                $actor,
                (int) $order['id'],
                sprintf('Returned from shipment %s', (string) $shipment['uuid'])
            );
        }
    }

    /**
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    private function details(array $changes): array
    {
        $details = [];

        foreach (self::DETAIL_FIELDS as $field) {
            if (!array_key_exists($field, $changes)) {
                continue;
            }

            $details[$field] = $field === 'photo_attachment_id'
                ? ((int) $changes[$field] > 0 ? (int) $changes[$field] : null)
                : $this->text($changes[$field]);
        }

        return $details;
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, 255);
    }

    private function actorType(Actor $actor): string
    {
        return match ($actor->type) {
            Actor::STAFF, Actor::OWNER_IMPERSONATION => 'staff',
            Actor::CUSTOMER => 'customer',
            default => 'system',
        };
    }
}
