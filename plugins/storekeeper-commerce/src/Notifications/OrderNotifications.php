<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Notifications;

use StoreKeeper\Commerce\Core\Money;

/**
 * Order messages (§12.2): a confirmation to the buyer who gave an email address
 * and a new-order alert to the store. Both are queued, never sent, inside the
 * checkout request.
 */
final class OrderNotifications
{
    public function __construct(private readonly MessageService $messages)
    {
    }

    /**
     * @param array<string,mixed> $order
     * @param array{store_name:string,order_url:string,bank_details:string} $store
     */
    public function orderCreated(
        array $order,
        int $itemsCount,
        ?string $buyerEmail,
        ?string $staffEmail,
        array $store,
    ): void {
        $address = json_decode((string) ($order['shipping_address'] ?? ''), true);
        $address = is_array($address) ? $address : [];

        $variables = [
            'order_number' => (string) $order['number'],
            'customer_name' => (string) ($address['recipient_name'] ?? 'Customer'),
            'customer_phone' => (string) ($address['phone_e164'] ?? ''),
            'items_count' => $itemsCount,
            'order_total' => (new Money((int) $order['total_kobo']))->format(),
            'order_url' => $store['order_url'],
            'store_name' => $store['store_name'],
            'bank_details' => $store['bank_details'],
        ];

        $context = [
            'order_id' => (int) $order['id'],
            'customer_id' => (int) ($order['customer_id'] ?? 0),
        ];

        if ($this->isEmail($buyerEmail)) {
            $this->messages->queue('order_created_buyer', (string) $buyerEmail, $variables, $context);
        }

        if ($this->isEmail($staffEmail)) {
            $this->messages->queue('order_created_staff', (string) $staffEmail, $variables, $context);
        }
    }

    /**
     * Tell the buyer a parcel moved (§14.1, §14.3). Only dispatched and
     * delivered parcels queue a message; packing is an internal step.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $shipment
     * @param array{store_name:string,order_url:string,tracking_url:string} $store
     */
    public function shipmentUpdated(
        array $order,
        array $shipment,
        string $status,
        ?string $buyerEmail,
        array $store,
    ): void {
        $event = match ($status) {
            'dispatched' => 'shipment_dispatched',
            'ready_for_pickup' => 'shipment_ready_for_pickup',
            'delivered' => 'shipment_delivered',
            default => null,
        };

        if ($event === null || !$this->isEmail($buyerEmail)) {
            return;
        }

        $address = json_decode((string) ($order['shipping_address'] ?? ''), true);
        $address = is_array($address) ? $address : [];

        $this->messages->queue($event, (string) $buyerEmail, [
            'order_number' => (string) $order['number'],
            'customer_name' => (string) ($address['recipient_name'] ?? 'Customer'),
            'status' => ucfirst(str_replace('_', ' ', $status)),
            'carrier' => (string) ($shipment['carrier'] ?? ''),
            'waybill_number' => (string) ($shipment['waybill_number'] ?? ''),
            'rider_name' => (string) ($shipment['rider_name'] ?? ''),
            'rider_phone' => (string) ($shipment['rider_phone'] ?? ''),
            'order_url' => $store['order_url'],
            'tracking_url' => $store['tracking_url'],
            'store_name' => $store['store_name'],
        ], [
            'order_id' => (int) $order['id'],
            'customer_id' => (int) ($order['customer_id'] ?? 0),
        ]);
    }

    private function isEmail(?string $email): bool
    {
        return $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
