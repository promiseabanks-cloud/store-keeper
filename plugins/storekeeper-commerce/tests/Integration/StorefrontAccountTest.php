<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Tests\Support\TestStore;

/**
 * Sign-up and the account area (§11.1, §11.2): approval is the group's rule, and
 * a repeat order only ever touches the signed-in customer's own order.
 */
final class StorefrontAccountTest extends TestCase
{
    private TestStore $store;

    private int $variantId;

    private int $productId;

    protected function setUp(): void
    {
        $this->store = TestStore::create();
        $this->store->seedGroups();
        $location = $this->store->seedLocation();
        $seeded = $this->store->seedProduct((int) $location['id'], 500000, 20);
        $this->variantId = (int) $seeded['variant']['id'];
        $this->productId = (int) $seeded['product']['id'];
    }

    public function testTheSignUpChoicesComeFromSelfRegisterableGroups(): void
    {
        $labels = array_column($this->store->registration->options(), 'name');

        self::assertSame(['Retail', 'Wholesale'], $labels);
    }

    public function testAnAutoApprovedResellerKeepsTheWholesaleGroupAtOnce(): void
    {
        $wholesale = $this->store->groupNamed('Wholesale');

        $clean = $this->store->registration->validate([
            'name' => 'Ada Obi',
            'phone' => '08031234567',
            'group_id' => $wholesale['id'],
            'business_name' => 'Ada Fabrics',
        ]);

        $phone = $this->store->auth->requestRegistration('08031234567');
        $code = (string) $this->store->otpSender->lastCode($phone);

        self::assertSame($phone, $this->store->auth->verifyRegistration($phone, $code));

        $customer = $this->store->registration->register($clean, $phone);

        self::assertSame('approved', $customer['approval_status']);
        self::assertSame((int) $wholesale['id'], (int) $customer['group_id']);
        self::assertNull($customer['requested_group_id']);
        self::assertSame('storefront', $customer['source']);
        self::assertSame('Ada Fabrics', $customer['business_name']);
    }

    public function testAManualGroupLeavesTheShopperPendingAtRetailPrices(): void
    {
        $wholesale = $this->store->groupNamed('Wholesale');
        $retail = $this->store->groupNamed('Retail');
        $this->store->db->update(
            $this->store->db->prefix() . 'customer_groups',
            ['approval' => 'manual'],
            ['id' => $wholesale['id']]
        );

        $clean = $this->store->registration->validate([
            'name' => 'Bola Trade',
            'group_id' => $wholesale['id'],
            'business_name' => 'Bola Stores',
        ]);

        $phone = $this->store->auth->requestRegistration('08035550000');
        $code = (string) $this->store->otpSender->lastCode($phone);
        $this->store->auth->verifyRegistration($phone, $code);

        $customer = $this->store->registration->register($clean, $phone);

        self::assertSame('pending', $customer['approval_status']);
        self::assertSame((int) $retail['id'], (int) $customer['group_id'], 'Pending shoppers stay on retail prices');
        self::assertSame((int) $wholesale['id'], (int) $customer['requested_group_id']);
    }

    public function testARegisteredNumberCannotSignUpTwice(): void
    {
        $this->store->wholesaleCustomer('08031234567');

        $this->expectException(ValidationFailed::class);
        $this->expectExceptionMessage('already registered');

        $this->store->auth->requestRegistration('0803 123 4567');
    }

    public function testWholesaleSignUpRequiresABusinessName(): void
    {
        $wholesale = $this->store->groupNamed('Wholesale');

        $this->expectException(ValidationFailed::class);
        $this->expectExceptionMessage('business name');

        $this->store->registration->validate([
            'name' => 'No Business',
            'group_id' => $wholesale['id'],
        ]);
    }

    public function testARetailSignUpNeedsNoBusinessName(): void
    {
        $retail = $this->store->groupNamed('Retail');

        $clean = $this->store->registration->validate([
            'name' => 'Ada Retail',
            'group_id' => $retail['id'],
            'business_name' => '',
        ]);

        self::assertNull($clean['business_name']);
        self::assertSame((int) $retail['id'], $clean['group_id']);
    }

    public function testTheAccountListsOnlyThisCustomersOrders(): void
    {
        $mine = $this->store->wholesaleCustomer('08031234567');
        $theirs = $this->store->wholesaleCustomer('08039999999');
        $myOrder = $this->checkout($mine, 2);
        $this->checkout($theirs, 1);

        $orders = $this->store->account->orders((int) $mine['id']);

        self::assertCount(1, $orders);
        self::assertSame($myOrder, $orders[0]['number']);
    }

    public function testRepeatingAnOrderRefillsTheCart(): void
    {
        $customer = $this->store->wholesaleCustomer('08031234567');
        $number = $this->checkout($customer, 3);

        $cart = $this->store->cart->open(hash('sha256', 'repeat-cart'));
        $result = $this->store->account->repeat($customer, $number, $cart);

        self::assertSame(1, $result['added']);
        self::assertSame([], $result['skipped']);
        $refilled = $this->store->cart->find(hash('sha256', 'repeat-cart'));
        self::assertNotNull($refilled);
        self::assertSame(3, $this->store->cart->summary($refilled, $customer)['item_count']);
    }

    public function testRepeatingSkipsALineThatIsNoLongerSellable(): void
    {
        $customer = $this->store->wholesaleCustomer('08031234567');
        $number = $this->checkout($customer, 1);

        // The product leaves the catalogue after the order was placed.
        $this->store->db->update(
            $this->store->db->prefix() . 'products',
            ['status' => 'archived'],
            ['id' => $this->productId]
        );

        $cart = $this->store->cart->open(hash('sha256', 'repeat-archived'));
        $result = $this->store->account->repeat($customer, $number, $cart);

        self::assertSame(0, $result['added']);
        self::assertCount(1, $result['skipped']);
    }

    public function testAnotherCustomersOrderCannotBeRepeated(): void
    {
        $owner = $this->store->wholesaleCustomer('08031234567');
        $stranger = $this->store->wholesaleCustomer('08039999999');
        $number = $this->checkout($owner, 1);

        $cart = $this->store->cart->open(hash('sha256', 'repeat-stranger'));

        $this->expectException(ValidationFailed::class);
        $this->expectExceptionMessage('not yours');

        $this->store->account->repeat($stranger, $number, $cart);
    }

    /** @param array<string,mixed> $customer */
    private function checkout(array $customer, int $qty): string
    {
        $token = hash('sha256', uniqid('account-cart', true));
        $cart = $this->store->cart->open($token, (int) $customer['id']);
        $this->store->cart->add($cart, ['variant_id' => $this->variantId, 'qty' => $qty], $customer);

        $result = $this->store->checkout->place([
            'name' => (string) $customer['name'],
            'phone' => (string) $customer['phone_e164'],
            'shipping' => 'pickup',
            'payment_method' => 'bank_transfer',
        ], $token, $customer);

        return (string) $result['order']['number'];
    }
}
