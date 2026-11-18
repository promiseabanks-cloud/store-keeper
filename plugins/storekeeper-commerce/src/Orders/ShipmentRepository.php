<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Orders;

use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;

/**
 * Parcels, their lines and their timeline (§8.4 `shipments`, `shipment_items`,
 * `shipment_events`).
 */
final class ShipmentRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert($this->db->prefix() . 'shipments', $data);
    }

    /** @return array<string,mixed>|null */
    public function shipment(int $shipmentId): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %sshipments WHERE id = ?', $this->db->prefix()),
            [$shipmentId]
        );
    }

    /** @return array<string,mixed>|null */
    public function shipmentByUuid(string $uuid): ?array
    {
        return $this->db->first(
            sprintf('SELECT * FROM %sshipments WHERE uuid = ?', $this->db->prefix()),
            [$uuid]
        );
    }

    /** @return list<array<string,mixed>> */
    public function forOrder(int $orderId): array
    {
        return $this->db->select(
            sprintf('SELECT * FROM %sshipments WHERE order_id = ? ORDER BY id ASC', $this->db->prefix()),
            [$orderId]
        );
    }

    public function addItem(int $shipmentId, int $orderItemId, int $qtyPieces): void
    {
        $this->db->insert($this->db->prefix() . 'shipment_items', [
            'shipment_id' => $shipmentId,
            'order_item_id' => $orderItemId,
            'qty_pieces' => $qtyPieces,
        ]);
    }

    /**
     * The shipment's lines with the order line's names for the tracking page.
     *
     * @return list<array<string,mixed>>
     */
    public function items(int $shipmentId): array
    {
        return $this->db->select(
            sprintf(
                'SELECT si.shipment_id, si.order_item_id, si.qty_pieces AS qty_pieces,
                        oi.product_name, oi.variant_title, oi.sku, oi.pack_name,
                        oi.qty_pieces AS order_qty_pieces,
                        oi.qty_fulfilled_pieces AS order_qty_fulfilled_pieces
                   FROM %sshipment_items si
                   JOIN %sorder_items oi ON oi.id = si.order_item_id
                  WHERE si.shipment_id = ?
                  ORDER BY si.order_item_id ASC',
                $this->db->prefix(),
                $this->db->prefix()
            ),
            [$shipmentId]
        );
    }

    /**
     * Pieces held by parcels that are not packed yet. A packed parcel's pieces
     * are already counted in `order_items.qty_fulfilled_pieces`, so counting
     * them here too would hide them from the next parcel.
     */
    public function allocatedPieces(int $orderItemId): int
    {
        return (int) $this->db->scalar(
            sprintf(
                "SELECT COALESCE(SUM(si.qty_pieces), 0)
                   FROM %sshipment_items si
                   JOIN %sshipments s ON s.id = si.shipment_id
                  WHERE si.order_item_id = ?
                    AND s.status = 'pending'",
                $this->db->prefix(),
                $this->db->prefix()
            ),
            [$orderItemId]
        );
    }

    public function addEvent(
        int $shipmentId,
        string $status,
        ?string $note,
        string $actorType,
        ?int $actorId,
        bool $notified = false,
    ): int {
        return $this->db->insert($this->db->prefix() . 'shipment_events', [
            'shipment_id' => $shipmentId,
            'status' => $status,
            'note' => $note,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'notified' => $notified ? 1 : 0,
            'created_at' => $this->clock->nowString(),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function events(int $shipmentId): array
    {
        return $this->db->select(
            sprintf('SELECT * FROM %sshipment_events WHERE shipment_id = ? ORDER BY id ASC', $this->db->prefix()),
            [$shipmentId]
        );
    }

    /** @param array<string,mixed> $data */
    public function update(int $shipmentId, array $data): void
    {
        $data['updated_at'] = $this->clock->nowString();
        $this->db->update($this->db->prefix() . 'shipments', $data, ['id' => $shipmentId]);
    }

    /**
     * Parcels across orders for the staff screens (§14.2), newest first. The
     * join carries what a packing slip or dispatch sheet needs without a
     * second query per row.
     *
     * @return list<array<string,mixed>>
     */
    public function listWithOrder(?string $status, int $limit): array
    {
        $sql = sprintf(
            'SELECT s.*, o.uuid AS order_uuid, o.number AS order_number,
                    o.payment_status AS order_payment_status,
                    o.fulfilment_status AS order_fulfilment_status,
                    o.shipping_address AS order_shipping_address,
                    (SELECT COALESCE(SUM(si.qty_pieces), 0)
                       FROM %sshipment_items si
                      WHERE si.shipment_id = s.id) AS pieces
               FROM %sshipments s
               JOIN %sorders o ON o.id = s.order_id',
            $this->db->prefix(),
            $this->db->prefix(),
            $this->db->prefix()
        );

        $params = [];

        if ($status !== null && $status !== '') {
            $sql .= ' WHERE s.status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY s.id DESC LIMIT ?';
        $params[] = max(1, $limit);

        return $this->db->select($sql, $params);
    }

    /** @return list<array<string,mixed>> parcels with items and timeline */
    public function forOrderDetailed(int $orderId): array
    {
        $shipments = $this->forOrder($orderId);

        foreach ($shipments as $index => $shipment) {
            $shipments[$index]['items'] = $this->items((int) $shipment['id']);
            $shipments[$index]['events'] = $this->events((int) $shipment['id']);
        }

        return $shipments;
    }
}
