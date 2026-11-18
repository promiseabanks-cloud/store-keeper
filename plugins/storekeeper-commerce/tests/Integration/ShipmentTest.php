<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Orders\Exception\InvalidTransition;
use StoreKeeper\Commerce\Orders\FulfilmentStatus;
use StoreKeeper\Commerce\Orders\PaymentStatus;
use StoreKeeper\Commerce\Orders\ShipmentStatus;
use StoreKeeper\Commerce\Tests\Support\TestStore;

/**
 * A parcel commits its own stock, moves through its own state machine and
 * writes a timeline the customer can read (§14.1).
 */
final class ShipmentTest extends TestCase
{
    private TestStore $store;

    private int $locationId;

    private int $variantId;

    protected function setUp(): void
    {
        $this->store = TestStore::create();
        $this->store->seedGroups();
        $this->locationId = (int) $this->store->seedLocation()['id'];
        $this->variantId = (int) $this->store->seedProduct($this->locationId, 500000, 20)['variant']['id'];
    }

    public function testAParcelDefaultsToEverythingNotYetPacked(): void
    {
        $order = $this->order(5);

        $shipment = $this->store->shipments->create((int) $order['id'], [], $this->store->actor());

        self::assertSame(ShipmentStatus::PENDING, $shipment['status']);
        self::assertCount(1, $shipment['items']);
        self::assertSame(5, (int) $shipment['items'][0]['qty_pieces']);
        self::assertSame('Parcel created.', $shipment['events'][0]['note']);

        $this->expectException(ValidationFailed::class);
        $this->store->shipments->create((int) $order['id'], [], $this->store->actor());
    }

    public function testAParcelCannotBeLargerThanTheLine(): void
    {
        $order = $this->order(2);
        $itemId = (int) $this->store->orders->items((int) $order['id'])[0]['id'];

        $this->expectException(ValidationFailed::class);

        $this->store->shipments->create((int) $order['id'], [
            ['order_item_id' => $itemId, 'qty_pieces' => 3],
        ], $this->store->actor());
    }

    public function testPackingCommitsOnlyThatParcelsStock(): void
    {
        $order = $this->order(5);
        $itemId = (int) $this->store->orders->items((int) $order['id'])[0]['id'];

        $first = $this->store->shipments->create((int) $order['id'], [
            ['order_item_id' => $itemId, 'qty_pieces' => 2],
        ], $this->store->actor());

        $packed = $this->store->shipments->update((int) $first['id'], ['status' => 'packed'], $this->store->actor());

        self::assertSame(ShipmentStatus::PACKED, $packed['status']);
        self::assertNotNull($packed['packed_at']);

        $level = $this->store->inventory->level($this->variantId, $this->locationId);
        self::assertSame(18, (int) $level['on_hand'], 'Two pieces left the shelf');
        self::assertSame(3, (int) $level['reserved'], 'The rest of the order is still reserved');
        self::assertSame([], $this->store->inventory->verify());

        $order = $this->store->orders->refreshStatuses((int) $order['id'], $this->store->actor());
        self::assertSame(FulfilmentStatus::PARTIALLY_FULFILLED, $order['fulfilment_status']);

        $second = $this->store->shipments->create((int) $order['id'], [], $this->store->actor());
        self::assertSame(3, (int) $second['items'][0]['qty_pieces'], 'The rest is still available to pack');
    }

    public function testDispatchingNeedsACarrierAndRecordsIt(): void
    {
        $order = $this->order(1);
        $shipment = $this->store->shipments->create((int) $order['id'], [], $this->store->actor());
        $this->store->shipments->update((int) $shipment['id'], ['status' => 'packed'], $this->store->actor());

        try {
            $this->store->shipments->update((int) $shipment['id'], ['status' => 'dispatched'], $this->store->actor());
            self::fail('Expected dispatch to require a carrier.');
        } catch (ValidationFailed) {
            self::assertSame('packed', $this->store->shipments->find((int) $shipment['id'])['status']);
        }

        $sent = $this->store->shipments->update((int) $shipment['id'], [
            'status' => 'dispatched',
            'carrier' => 'GIG Logistics',
            'waybill_number' => 'GIG-7781',
            'rider_name' => 'Musa',
            'rider_phone' => '08035550000',
        ], $this->store->actor());

        self::assertSame('GIG Logistics', $sent['carrier']);
        self::assertSame('GIG-7781', $sent['waybill_number']);
        self::assertNotNull($sent['dispatched_at']);
        self::assertSame(
            ['pending', 'packed', 'dispatched'],
            array_column($sent['events'], 'status'),
            'The timeline records each step in order'
        );
    }

    public function testTheLifecycleRefusesShortcuts(): void
    {
        $order = $this->order(1);
        $shipment = $this->store->shipments->create((int) $order['id'], [], $this->store->actor());

        try {
            $this->store->shipments->update((int) $shipment['id'], ['status' => 'delivered'], $this->store->actor());
            self::fail('A parcel cannot be delivered before it was packed.');
        } catch (InvalidTransition $error) {
            self::assertSame('shipment', $error->subject);
        }

        $this->store->shipments->update((int) $shipment['id'], ['status' => 'cancelled'], $this->store->actor());

        self::assertSame(ShipmentStatus::CANCELLED, $this->store->shipments->find((int) $shipment['id'])['status']);
        self::assertSame(20, (int) $this->store->inventory->level($this->variantId, $this->locationId)['on_hand']);
    }

    public function testAFailedDeliveryNeedsAReason(): void
    {
        $order = $this->order(1);
        $shipment = $this->store->shipments->create((int) $order['id'], [], $this->store->actor());
        $this->store->shipments->update((int) $shipment['id'], ['status' => 'packed'], $this->store->actor());
        $this->store->shipments->update((int) $shipment['id'], [
            'status' => 'ready_for_pickup',
        ], $this->store->actor());

        $this->expectException(ValidationFailed::class);

        $this->store->shipments->update((int) $shipment['id'], [
            'status' => 'failed_delivery',
        ], $this->store->actor());
    }

    public function testDeliveringAPayOnDeliveryParcelRecordsTheCash(): void
    {
        $order = $this->order(2, ['payment_method' => 'pay_on_delivery']);
        $total = (int) $order['total_kobo'];

        $shipment = $this->store->shipments->create((int) $order['id'], [], $this->store->actor());
        $shipmentId = (int) $shipment['id'];

        $this->store->shipments->update($shipmentId, ['status' => 'packed'], $this->store->actor());
        $this->store->shipments->update($shipmentId, [
            'status' => 'dispatched',
            'carrier' => 'Shop rider',
        ], $this->store->actor());
        $delivered = $this->store->shipments->update($shipmentId, [
            'status' => 'delivered',
            'collected_kobo' => $total,
        ], $this->store->actor());

        self::assertSame(ShipmentStatus::DELIVERED, $delivered['status']);

        $order = $this->store->orders->find((int) $order['id']);
        self::assertSame(PaymentStatus::PAID, $order['payment_status']);
        self::assertSame($total, (int) $order['paid_kobo']);
        self::assertSame(FulfilmentStatus::FULFILLED, $order['fulfilment_status']);
        self::assertCount(1, $this->store->orders->payments((int) $order['id']));
    }

    public function testAReturnCanPutTheGoodsBackOnTheShelf(): void
    {
        $order = $this->order(2);
        $shipment = $this->store->shipments->create((int) $order['id'], [], $this->store->actor());
        $shipmentId = (int) $shipment['id'];

        $this->store->shipments->update($shipmentId, ['status' => 'packed'], $this->store->actor());
        $this->store->shipments->update($shipmentId, ['status' => 'dispatched', 'carrier' => 'Rider'], $this->store->actor());
        $this->store->shipments->update($shipmentId, ['status' => 'failed_delivery', 'note' => 'Nobody home'], $this->store->actor());

        self::assertSame(18, (int) $this->store->inventory->level($this->variantId, $this->locationId)['on_hand']);

        $returned = $this->store->shipments->update($shipmentId, [
            'status' => 'returned',
            'restock' => true,
            'note' => 'Back at the shop',
        ], $this->store->actor());

        self::assertSame(ShipmentStatus::RETURNED, $returned['status']);
        self::assertSame(20, (int) $this->store->inventory->level($this->variantId, $this->locationId)['on_hand']);
        self::assertSame([], $this->store->inventory->verify());

        $order = $this->store->orders->find((int) $order['id']);
        self::assertSame(FulfilmentStatus::UNFULFILLED, $order['fulfilment_status']);
    }

    public function testBulkStatusMovesManyParcelsAndReportsFailures(): void
    {
        $first = $this->order(1, ['idempotency_key' => 'bulk-one']);
        $second = $this->order(1, ['idempotency_key' => 'bulk-two']);

        $a = $this->store->shipments->create((int) $first['id'], [], $this->store->actor());
        $b = $this->store->shipments->create((int) $second['id'], [], $this->store->actor());

        $result = $this->store->shipments->bulkStatus(
            [(int) $a['id'], (int) $b['id'], 999999],
            'packed',
            $this->store->actor()
        );

        self::assertCount(2, $result['updated']);
        self::assertArrayHasKey(999999, $result['failed']);
        self::assertSame(18, (int) $this->store->inventory->level($this->variantId, $this->locationId)['on_hand']);
    }

    public function testTheToPackListNamesTheOrderAndRecipient(): void
    {
        $order = $this->order(2, [
            'shipping_address' => [
                'recipient_name' => 'Ada Obi',
                'phone_e164' => '+2348031234567',
                'method' => 'delivery',
                'line1' => '12 Balogun Street',
                'lga' => 'Lagos Island',
                'state' => 'Lagos',
                'shipping_label' => 'Lagos Island delivery',
            ],
        ]);

        $shipment = $this->store->shipments->create((int) $order['id'], [], $this->store->actor());

        $rows = $this->store->shipments->toPack();

        self::assertCount(1, $rows);
        self::assertSame($shipment['uuid'], $rows[0]['uuid']);
        self::assertSame($order['number'], $rows[0]['order']['number']);
        self::assertSame('Ada Obi', $rows[0]['order']['recipient_name']);
        self::assertSame('+2348031234567', $rows[0]['order']['phone']);
        self::assertSame('12 Balogun Street', $rows[0]['order']['line1']);
        self::assertSame('Lagos', $rows[0]['order']['state']);
        self::assertSame(2, $rows[0]['pieces']);
        self::assertSame('Not packed yet', $rows[0]['status_label']);

        $this->store->shipments->update((int) $shipment['id'], ['status' => 'packed'], $this->store->actor());

        self::assertSame([], $this->store->shipments->toPack(), 'A packed parcel leaves the to-pack list');
        self::assertCount(1, $this->store->shipments->listing(ShipmentStatus::PACKED));
        self::assertCount(1, $this->store->shipments->listing(), 'No status filter lists every parcel');
    }

    public function testOneParcelCarriesItsOrderForThePackingSlip(): void
    {
        $order = $this->order(1, [
            'shipping_address' => ['recipient_name' => 'Ada Obi', 'phone_e164' => '+2348031234567'],
        ]);

        $shipment = $this->store->shipments->create((int) $order['id'], [], $this->store->actor());

        self::assertSame($order['number'], $shipment['order']['number']);
        self::assertSame('Ada Obi', $shipment['order']['shipping_address']['recipient_name']);
        self::assertStringStartsWith('Gold hoop earrings', (string) $shipment['items'][0]['product_name']);
    }

    /** @param array<string,mixed> $overrides */
    private function order(int $qty, array $overrides = []): array
    {
        $result = $this->store->orders->create([
            'channel' => 'web',
            'idempotency_key' => 'shipment-test-' . uniqid('', true),
            'lines' => [['variant_id' => $this->variantId, 'qty' => $qty]],
            'payment_method' => 'bank_transfer',
            ...$overrides,
        ], $this->store->actor());

        return $result['order'];
    }
}
