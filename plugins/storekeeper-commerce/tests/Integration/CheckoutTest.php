<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Orders\OrderStatus;
use StoreKeeper\Commerce\Orders\PaymentStatus;
use StoreKeeper\Commerce\Tests\Support\TestStore;

/**
 * Checkout composes the order engine (§22.5): it never prices a line itself,
 * reserves through InventoryService, and a repeated submit returns the order
 * that already exists.
 */
final class CheckoutTest extends TestCase
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

    public function testAGuestCanCheckOutAndTheOrderReservesItsStock(): void
    {
        $token = $this->cartWith(2);

        $result = $this->store->checkout->place($this->input(), $token, null);
        $order = $result['order'];

        self::assertSame('web', $order['channel']);
        self::assertNull($order['customer_id'], 'Guest orders are not attached to a customer record');
        self::assertSame('system', $order['created_by_type']);
        self::assertSame(1000000, (int) $order['total_kobo']);
        self::assertSame(0, (int) $order['shipping_kobo']);
        self::assertSame(OrderStatus::OPEN, $order['status']);
        self::assertSame(PaymentStatus::UNPAID, $order['payment_status']);
        self::assertNotNull($order['hold_expires_at'], 'A bank transfer order keeps its hold');

        self::assertSame(2, (int) $this->store->inventory->level($this->variantId, $this->locationId)['reserved']);

        $cart = $this->store->cart->find($token);
        self::assertSame('converted', $cart['status']);
        self::assertSame((int) $order['id'], (int) $cart['converted_order_id']);
        self::assertSame(0, $this->store->cart->lineCount($this->store->cart->find($token)));
    }

    public function testASecondSubmitReturnsTheSameOrderAndChargesNothingTwice(): void
    {
        $token = $this->cartWith(1);

        $first = $this->store->checkout->place($this->input(), $token, null);
        $second = $this->store->checkout->place($this->input(), $token, null);

        self::assertFalse($first['replayed']);
        self::assertTrue($second['replayed']);
        self::assertSame((int) $first['order']['id'], (int) $second['order']['id']);

        self::assertSame(1, (int) $this->store->db->scalar(
            sprintf('SELECT COUNT(*) FROM %sorders', $this->store->db->prefix())
        ));
        self::assertSame(1, (int) $this->store->inventory->level($this->variantId, $this->locationId)['reserved']);
    }

    public function testDeliveryAddsTheFlatFeeAndKeepsTheAddress(): void
    {
        $token = $this->cartWith(2);

        $result = $this->store->checkout->place([
            ...$this->input(),
            'shipping' => 'delivery',
            'line1' => '12 Balogun Street',
            'lga' => 'Lagos Island',
            'state' => 'Lagos',
            'landmark' => 'By the market',
        ], $token, null);

        $order = $result['order'];
        self::assertSame(150000, (int) $order['shipping_kobo']);
        self::assertSame(1150000, (int) $order['total_kobo']);

        $address = json_decode((string) $order['shipping_address'], true);
        self::assertSame('delivery', $address['method']);
        self::assertSame('12 Balogun Street', $address['line1']);
        self::assertSame('+2348031234567', $address['phone_e164']);
    }

    public function testDeliveryWithoutAnAddressIsRefusedBeforeAnyOrderExists(): void
    {
        $token = $this->cartWith(1);

        try {
            $this->store->checkout->place([...$this->input(), 'shipping' => 'delivery'], $token, null);
            self::fail('Expected delivery to require an address.');
        } catch (ValidationFailed $error) {
            self::assertStringContainsString('Street address', $error->getMessage());
        }

        self::assertSame(0, (int) $this->store->db->scalar(
            sprintf('SELECT COUNT(*) FROM %sorders', $this->store->db->prefix())
        ));
        self::assertSame('active', $this->store->cart->find($token)['status']);
    }

    public function testStockTakenBetweenCartAndSubmitBlocksCheckoutAndCreatesNothing(): void
    {
        $token = $this->cartWith(2);
        $this->fillCart();

        try {
            $this->store->checkout->place($this->input(), $token, null);
            self::fail('Expected checkout to refuse a cart whose stock is gone.');
        } catch (ValidationFailed $error) {
            self::assertStringContainsString('need attention', $error->getMessage());
        }

        self::assertSame(1, (int) $this->store->db->scalar(
            sprintf('SELECT COUNT(*) FROM %sorders', $this->store->db->prefix())
        ), 'Only the other shopper\'s order exists');
        self::assertSame('active', $this->store->cart->find($token)['status']);
        self::assertSame([], $this->store->inventory->verify());

        // The engine still guards the race after the summary: if stock vanishes
        // between the check and the reservation, OrderService rolls it all back.
        $level = $this->store->inventory->level($this->variantId, $this->locationId);
        self::assertSame(20, (int) $level['on_hand'], 'Reserving does not remove goods from the shelf');
        self::assertSame(20, (int) $level['reserved'], 'The other shopper holds the whole shelf');
    }

    public function testAnEmptyCartCannotCheckOut(): void
    {
        $token = hash('sha256', 'empty-cart');

        $this->expectException(ValidationFailed::class);

        $this->store->checkout->place($this->input(), $token, null);
    }

    public function testTheGroupsMinimumOrderValueIsEnforced(): void
    {
        $this->store->db->update(
            $this->store->db->prefix() . 'customer_groups',
            ['min_order_value_kobo' => 2000000],
            ['is_default' => 1]
        );

        $token = $this->cartWith(1);

        try {
            $this->store->checkout->place($this->input(), $token, null);
            self::fail('Expected the minimum order value to block checkout.');
        } catch (ValidationFailed $error) {
            self::assertStringContainsString('minimum order value', $error->getMessage());
        }
    }

    public function testAnUnknownPaymentMethodIsRefused(): void
    {
        $token = $this->cartWith(1);

        $this->expectException(ValidationFailed::class);

        $this->store->checkout->place([...$this->input(), 'payment_method' => 'cowrie_shells'], $token, null);
    }

    public function testAGuestTypingSomeoneElsesPhoneDoesNotOrderOnTheirAccount(): void
    {
        $customer = $this->store->wholesaleCustomer('08031234567');
        $token = $this->cartWith(1);

        $result = $this->store->checkout->place($this->input(['phone' => '08031234567']), $token, null);

        self::assertNull($result['order']['customer_id']);
        self::assertNotSame((int) $customer['id'], (int) $result['order']['customer_id']);
    }

    public function testASignedInCustomerKeepsTheirIdentityAndGroup(): void
    {
        $customer = $this->store->wholesaleCustomer();
        $token = $this->cartWith(2);

        $result = $this->store->checkout->place($this->input(), $token, $customer);

        self::assertSame((int) $customer['id'], (int) $result['order']['customer_id']);
        self::assertSame('customer', $result['order']['created_by_type']);
        self::assertSame((int) $customer['group_id'], (int) $result['order']['customer_group_id']);
    }

    public function testTheOptionalEmailIsKeptForTheConfirmation(): void
    {
        $token = $this->cartWith(1);

        $result = $this->store->checkout->place($this->input(['email' => 'Ada@Example.com']), $token, null);
        $address = json_decode((string) $result['order']['shipping_address'], true);

        self::assertSame('ada@example.com', $address['email']);
    }

    public function testABadEmailIsRefusedBeforeAnyOrderIsCreated(): void
    {
        $token = $this->cartWith(1);

        try {
            $this->store->checkout->place($this->input(['email' => 'not-an-email']), $token, null);
            self::fail('Expected a bad email address to be refused.');
        } catch (ValidationFailed $error) {
            self::assertStringContainsString('email address', $error->getMessage());
        }

        self::assertSame(0, (int) $this->store->db->scalar(
            sprintf('SELECT COUNT(*) FROM %sorders', $this->store->db->prefix())
        ));
    }

    /** @param array<string,mixed> $overrides */
    private function input(array $overrides = []): array
    {
        return [
            'name' => 'Ada Obi',
            'phone' => '08031234567',
            'shipping' => 'pickup',
            'payment_method' => 'bank_transfer',
            'note' => 'Call before delivery',
            ...$overrides,
        ];
    }

    private function cartWith(int $qty): string
    {
        $token = hash('sha256', uniqid('cart', true));
        $cart = $this->store->cart->open($token);
        $this->store->cart->add($cart, ['variant_id' => $this->variantId, 'qty' => $qty], null);

        return $token;
    }

    private function fillCart(): void
    {
        $this->store->orders->create([
            'channel' => 'other',
            'idempotency_key' => 'fill-the-shelf',
            'lines' => [['variant_id' => $this->variantId, 'qty' => 20]],
        ], $this->store->actor());
    }
}
