<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Core\Money;
use StoreKeeper\Commerce\Tests\Support\TestStore;

final class CustomerServiceTest extends TestCase
{
    private TestStore $store;

    protected function setUp(): void
    {
        $this->store = TestStore::create();
        $this->store->seedGroups();
    }

    public function testWholesaleRegistrationIsAutoApprovedByDefault(): void
    {
        $wholesale = $this->store->groupNamed('Wholesale');

        $customer = $this->store->customers->create([
            'name' => 'Ada Obi',
            'business_name' => 'Ada Fabrics',
            'phone' => '0803 123 4567',
            'group_id' => $wholesale['id'],
        ], $this->store->actor(), 'storefront');

        self::assertSame('approved', $customer['approval_status']);
        self::assertSame((int) $wholesale['id'], (int) $customer['group_id']);
        self::assertSame('+2348031234567', $customer['phone_e164']);
        self::assertNull($customer['requested_group_id']);
        self::assertTrue($this->store->hooks->hasFired('customer/registered'));
    }

    public function testManualApprovalGroupKeepsTheShopperInRetailUntilApproved(): void
    {
        $wholesale = $this->store->groupNamed('Wholesale');
        $retail = $this->store->groupNamed('Retail');
        $this->store->db->update(
            $this->store->db->prefix() . 'customer_groups',
            ['approval' => 'manual'],
            ['id' => (int) $wholesale['id']]
        );

        $customer = $this->store->customers->create([
            'name' => 'Chidi Nwosu',
            'business_name' => 'Chidi Stores',
            'phone' => '08035554444',
            'group_id' => $wholesale['id'],
        ], $this->store->actor(), 'storefront');

        self::assertSame('pending', $customer['approval_status']);
        self::assertSame((int) $retail['id'], (int) $customer['group_id']);
        self::assertSame((int) $wholesale['id'], (int) $customer['requested_group_id']);

        $approved = $this->store->customers->approve((int) $customer['id'], $this->store->actor());

        self::assertSame('approved', $approved['approval_status']);
        self::assertSame((int) $wholesale['id'], (int) $approved['group_id']);
        self::assertNull($approved['requested_group_id']);
        self::assertTrue($this->store->hooks->hasFired('customer/approved'));
    }

    public function testStaffCreatedCustomersAreApprovedEvenInManualGroups(): void
    {
        $wholesale = $this->store->groupNamed('Wholesale');
        $this->store->db->update(
            $this->store->db->prefix() . 'customer_groups',
            ['approval' => 'manual'],
            ['id' => (int) $wholesale['id']]
        );

        $customer = $this->store->customers->create([
            'name' => 'Walk-in Reseller',
            'phone' => '08032223333',
            'group_id' => $wholesale['id'],
        ], $this->store->actor(), 'staff');

        self::assertSame('approved', $customer['approval_status']);
        self::assertSame((int) $wholesale['id'], (int) $customer['group_id']);
    }

    public function testDuplicatePhoneIsRejected(): void
    {
        $this->store->wholesaleCustomer('08031112222');

        $this->expectException(ValidationFailed::class);

        $this->store->customers->create([
            'name' => 'Another Ada',
            'phone' => '0803 111 2222',
        ], $this->store->actor(), 'storefront');
    }

    public function testFindOrCreateReusesThePhoneMatch(): void
    {
        $first = $this->store->customers->findOrCreate(['name' => 'Bola', 'phone' => '08034445555'], $this->store->actor());
        $second = $this->store->customers->findOrCreate(['name' => 'Bola Again', 'phone' => '+2348034445555'], $this->store->actor());

        self::assertSame((int) $first['id'], (int) $second['id']);
        self::assertSame('Bola', $second['name']);
    }

    public function testSpecialPricesAreStoredPerCustomer(): void
    {
        $customer = $this->store->wholesaleCustomer();
        $location = $this->store->seedLocation();
        $product = $this->store->seedProduct((int) $location['id']);

        $this->store->customers->setSpecialPrice(
            (int) $customer['id'],
            (int) $product['variant']['id'],
            null,
            new Money(123456),
            $this->store->actor()
        );

        $prices = $this->store->customers->specialPrices((int) $customer['id']);

        self::assertCount(1, $prices);
        self::assertSame(123456, (int) $prices[0]['amount_kobo']);
    }

    public function testAddressesRequireStateAndLga(): void
    {
        $customer = $this->store->wholesaleCustomer();
        $this->expectException(ValidationFailed::class);

        $this->store->customers->addAddress((int) $customer['id'], [
            'recipient_name' => 'Ada',
            'phone' => '08031234567',
            'line1' => '12 Balogun Street',
        ], $this->store->actor());
    }

    public function testAddressIsStoredWithNormalisedPhone(): void
    {
        $customer = $this->store->wholesaleCustomer();

        $address = $this->store->customers->addAddress((int) $customer['id'], [
            'recipient_name' => 'Ada',
            'phone' => '0803 123 4567',
            'line1' => '12 Balogun Street',
            'lga' => 'Lagos Island',
            'state' => 'Lagos',
            'is_default' => true,
        ], $this->store->actor());

        self::assertSame('+2348031234567', $address['phone_e164']);
        self::assertSame(1, (int) $address['is_default']);
        self::assertCount(1, $this->store->customers->addresses((int) $customer['id']));
    }
}
