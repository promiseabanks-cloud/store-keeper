<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Core\Money;
use StoreKeeper\Commerce\Tests\Support\TestStore;

/**
 * The queries behind the Phase 4 screens: who the customers are, what each
 * price list charges, and which shops stock lives in.
 */
final class BackOfficeQueriesTest extends TestCase
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

    public function testTheCustomerListShowsTheGroupAndFiltersBySearchAndApproval(): void
    {
        $wholesale = $this->store->wholesaleCustomer('08031112222');

        // A wholesale group can be set to approve applicants by hand.
        $this->store->db->update(
            $this->store->db->prefix() . 'customer_groups',
            ['approval' => 'manual'],
            ['id' => (int) $wholesale['group_id']]
        );

        $pending = $this->store->customers->create([
            'name' => 'Amaka Pending',
            'phone' => '08033334444',
            'group_id' => (int) $wholesale['group_id'],
        ], $this->store->actor(), 'storefront');

        self::assertSame('pending', $pending['approval_status']);

        $rows = $this->store->customerRepository->listWithGroup([]);
        self::assertCount(2, $rows);

        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['name']] = $row['group_name'];
        }
        self::assertSame('Wholesale', $groups['Ada Wholesale']);
        self::assertSame('Retail', $groups['Amaka Pending'], 'A pending applicant is priced at retail until approved');

        $onlyPending = $this->store->customerRepository->listWithGroup(['approval_status' => 'pending']);
        self::assertCount(1, $onlyPending);
        self::assertSame('Amaka Pending', $onlyPending[0]['name']);

        // Numbers are stored in E.164, so staff search the part without the trunk 0.
        $bySearch = $this->store->customerRepository->listWithGroup(['search' => '803111']);
        self::assertCount(1, $bySearch);
        self::assertSame('Ada Wholesale', $bySearch[0]['name']);

        // Approving moves them into the group they asked for.
        $approved = $this->store->customers->approve((int) $pending['id'], $this->store->actor());
        self::assertSame('approved', $approved['approval_status']);
        self::assertSame((int) $pending['requested_group_id'], (int) $approved['group_id']);
        self::assertNull($approved['requested_group_id']);
    }

    public function testAPriceListReturnsItsTiersWithProductNames(): void
    {
        $lists = $this->store->pricingRepository->lists();
        self::assertNotEmpty($lists);
        $retail = $this->store->pricingRepository->basePriceList();
        self::assertNotNull($retail);

        $this->store->setPrice((int) $retail['id'], $this->variantId, 450000);
        $this->store->setPrice((int) $retail['id'], $this->variantId, 400000, 12);

        $rows = $this->store->pricingRepository->pricesForList((int) $retail['id']);

        self::assertCount(2, $rows);
        self::assertSame(1, (int) $rows[0]['min_qty']);
        self::assertSame(450000, (int) $rows[0]['amount_kobo']);
        self::assertSame(12, (int) $rows[1]['min_qty']);
        self::assertStringContainsString('Gold hoop earrings', (string) $rows[0]['product_name']);

        $searched = $this->store->pricingRepository->pricesForList((int) $retail['id'], 'nothing-matches');
        self::assertSame([], $searched);
    }

    public function testALocationCanBeCreatedButNotNameless(): void
    {
        $warehouse = $this->store->inventory->createLocation([
            'name' => 'Ikeja warehouse',
            'type' => 'warehouse',
            'sellable_online' => false,
            'address' => '12 Kudirat Abiola Way',
        ], $this->store->actor());

        self::assertSame('Ikeja warehouse', $warehouse['name']);
        self::assertSame('warehouse', $warehouse['type']);
        self::assertSame(0, (int) $warehouse['sellable_online']);
        self::assertSame(1, (int) $warehouse['is_active']);
        self::assertTrue($this->store->hooks->hasFired('location/created'));

        $this->expectException(ValidationFailed::class);
        $this->store->inventory->createLocation(['name' => '   '], $this->store->actor());
    }

    public function testCustomerSpendFollowsTheirPaidOrders(): void
    {
        $ada = $this->store->wholesaleCustomer('08031112222');

        $order = $this->store->orders->create([
            'channel' => 'whatsapp',
            'payment_method' => 'bank_transfer',
            'customer_id' => (int) $ada['id'],
            'idempotency_key' => 'totals-1',
            'lines' => [['variant_id' => $this->variantId, 'qty' => 1]],
        ], $this->store->actor())['order'];

        $this->store->orders->recordPayment((int) $order['id'], ['method' => 'bank_transfer'], $this->store->actor());

        $updated = $this->store->customers->find((int) $ada['id']);

        self::assertSame(1, (int) $updated['order_count']);
        self::assertSame(500000, (int) $updated['total_spent_kobo']);
        self::assertNotNull($updated['last_order_at']);
    }

    public function testOrdersCanBeFilteredToOneCustomer(): void
    {
        $ada = $this->store->wholesaleCustomer('08031112222');
        $other = $this->store->customers->create([
            'name' => 'Chika Retail',
            'phone' => '08035556666',
        ], $this->store->actor());

        foreach ([$ada, $other] as $index => $customer) {
            $this->store->orders->create([
                'channel' => 'whatsapp',
                'payment_method' => 'bank_transfer',
                'customer_id' => (int) $customer['id'],
                'idempotency_key' => 'customer-filter-' . $index,
                'lines' => [['variant_id' => $this->variantId, 'qty' => 1]],
            ], $this->store->actor());
        }

        $rows = $this->store->orderRepository->listDetailed(['customer_id' => (int) $ada['id']]);

        self::assertCount(1, $rows);
        self::assertSame('Ada Wholesale', $rows[0]['customer_name']);

        $priced = $this->store->pricing->resolve(
            $this->variantId,
            null,
            3,
            $ada
        );
        self::assertSame(Money::class, get_class($priced->unitAmount));
    }
}
