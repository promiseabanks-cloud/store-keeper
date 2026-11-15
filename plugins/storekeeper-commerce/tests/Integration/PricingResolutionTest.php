<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Core\Money;
use StoreKeeper\Commerce\Pricing\Exception\PriceNotFound;
use StoreKeeper\Commerce\Pricing\PriceSource;
use StoreKeeper\Commerce\Tests\Support\TestStore;

final class PricingResolutionTest extends TestCase
{
    private TestStore $store;

    private int $locationId;

    private int $variantId;

    private int $retailListId;

    private int $wholesaleListId;

    protected function setUp(): void
    {
        $this->store = TestStore::create();
        $this->locationId = (int) $this->store->seedLocation()['id'];
        $this->store->seedGroups();

        $this->retailListId = (int) $this->store->pricingRepository->basePriceList()['id'];
        $this->wholesaleListId = (int) $this->store->groupNamed('Wholesale')['price_list_id'];

        $product = $this->store->seedProduct($this->locationId, 500000, 100);
        $this->variantId = (int) $product['variant']['id'];
    }

    public function testGuestGetsTheBaseRetailPrice(): void
    {
        $price = $this->store->pricing->resolve($this->variantId, null, 1, null);

        self::assertSame(500000, $price->unitAmount->minor());
        self::assertSame(PriceSource::BASE, $price->source);
        self::assertSame(1, $price->packPieces);
    }

    public function testBaseListQuantityTiersApplyToAnyone(): void
    {
        $this->store->setPrice($this->retailListId, $this->variantId, 450000, 12);

        $single = $this->store->pricing->resolve($this->variantId, null, 2, null);
        self::assertSame(500000, $single->unitAmount->minor());
        self::assertSame(PriceSource::BASE, $single->source);

        $bulk = $this->store->pricing->resolve($this->variantId, null, 12, null);
        self::assertSame(450000, $bulk->unitAmount->minor());
        self::assertSame(PriceSource::BASE_TIER, $bulk->source);
        self::assertSame(12, $bulk->minQty);
    }

    public function testApprovedWholesalerGetsTheirGroupPrice(): void
    {
        $this->store->setPrice($this->wholesaleListId, $this->variantId, 400000, 1);
        $customer = $this->store->wholesaleCustomer();

        $price = $this->store->pricing->resolve($this->variantId, null, 1, $customer);

        self::assertSame(400000, $price->unitAmount->minor());
        self::assertSame(PriceSource::GROUP, $price->source);
    }

    /**
     * R1: a known wholesaler topping up 3 pieces must still get the wholesale
     * price even though the wholesale tier starts at 12 (ignore_item_minimums).
     */
    public function testWholesalerBuyingBelowTheTierMinimumStillGetsWholesalePrice(): void
    {
        $this->store->setPrice($this->wholesaleListId, $this->variantId, 400000, 12);
        $customer = $this->store->wholesaleCustomer();

        $price = $this->store->pricing->resolve($this->variantId, null, 3, $customer);

        self::assertSame(400000, $price->unitAmount->minor());
        self::assertSame(PriceSource::GROUP_TIER, $price->source);
        self::assertSame(12, $price->minQty);
    }

    public function testGuestsNeverSeeWholesalePrices(): void
    {
        $this->store->setPrice($this->wholesaleListId, $this->variantId, 400000, 1);

        $price = $this->store->pricing->resolve($this->variantId, null, 1, null);

        self::assertSame(500000, $price->unitAmount->minor());
        self::assertSame(PriceSource::BASE, $price->source);
    }

    public function testCustomerSpecificPriceBeatsEveryList(): void
    {
        $this->store->setPrice($this->wholesaleListId, $this->variantId, 400000, 1);
        $customer = $this->store->wholesaleCustomer();

        $this->store->customers->setSpecialPrice(
            (int) $customer['id'],
            $this->variantId,
            null,
            new Money(380000),
            $this->store->actor()
        );

        $price = $this->store->pricing->resolve($this->variantId, null, 1, $customer);

        self::assertSame(380000, $price->unitAmount->minor());
        self::assertSame(PriceSource::CUSTOMER, $price->source);
    }

    public function testExpiredCustomerPriceIsIgnored(): void
    {
        $customer = $this->store->wholesaleCustomer();
        $this->store->customers->setSpecialPrice(
            (int) $customer['id'],
            $this->variantId,
            null,
            new Money(380000),
            $this->store->actor(),
            null,
            '2020-01-01 00:00:00.000'
        );

        $price = $this->store->pricing->resolve($this->variantId, null, 1, $customer);

        self::assertSame(500000, $price->unitAmount->minor());
        self::assertSame(PriceSource::BASE, $price->source);
    }

    public function testPackPriceIsDerivedFromThePiecePrice(): void
    {
        $pack = $this->store->catalog->addPackUnit('Dozen', 12);

        $price = $this->store->pricing->resolve($this->variantId, (int) $pack['id'], 1, null);

        self::assertSame(6000000, $price->unitAmount->minor());
        self::assertSame(12, $price->packPieces);
        self::assertSame(PriceSource::BASE, $price->source);
    }

    public function testExplicitPackPriceOverrideWins(): void
    {
        $pack = $this->store->catalog->addPackUnit('Dozen', 12);
        $this->store->setPrice($this->retailListId, $this->variantId, 5500000, 1, (int) $pack['id']);

        $price = $this->store->pricing->resolve($this->variantId, (int) $pack['id'], 1, null);

        self::assertSame(5500000, $price->unitAmount->minor());
        self::assertSame(12, $price->packPieces);
    }

    public function testMissingPriceThrows(): void
    {
        $unpriced = $this->store->catalog->createProduct([
            'name' => 'Unpriced item',
            'price_kobo' => null,
        ], $this->store->actor());

        $this->expectException(PriceNotFound::class);

        $this->store->pricing->resolve((int) $unpriced['variants'][0]['id'], null, 1, null);
    }

    public function testQuoteResolvesEveryLineAndSubtotals(): void
    {
        $this->store->setPrice($this->wholesaleListId, $this->variantId, 400000, 1);
        $customer = $this->store->wholesaleCustomer();

        $quote = $this->store->pricing->quote([
            ['variant_id' => $this->variantId, 'qty' => 3],
        ], $customer);

        self::assertSame(1200000, $quote['subtotal_kobo']);
        self::assertSame('NGN', $quote['currency']);
        self::assertSame(400000, $quote['lines'][0]['unit_amount_kobo']);
        self::assertSame('group', $quote['lines'][0]['source']);
    }

    public function testPendingCustomerFallsBackToRetailPrices(): void
    {
        $this->store->setPrice($this->wholesaleListId, $this->variantId, 400000, 1);

        $wholesaleGroup = $this->store->groupNamed('Wholesale');
        $customer = $this->store->customers->create([
            'name' => 'Pending Buyer',
            'phone' => '08039998888',
            'group_id' => $wholesaleGroup['id'],
        ], $this->store->actor(), 'staff');

        // Staff creation approves; simulate a pending storefront application.
        $this->store->db->update($this->store->db->prefix() . 'customers', [
            'approval_status' => 'pending',
            'group_id' => (int) $this->store->groupNamed('Retail')['id'],
            'requested_group_id' => (int) $wholesaleGroup['id'],
        ], ['id' => (int) $customer['id']]);

        $pending = $this->store->customerRepository->customer((int) $customer['id']);
        $price = $this->store->pricing->resolve($this->variantId, null, 1, $pending);

        self::assertSame(500000, $price->unitAmount->minor());
        self::assertSame(PriceSource::BASE, $price->source);
    }
}
