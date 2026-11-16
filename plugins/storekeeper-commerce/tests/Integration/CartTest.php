<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Tests\Support\TestStore;

/**
 * The cart stores choices, never prices (§22.5): every read re-resolves the
 * price for the shopper's group and the stock from the ledger.
 */
final class CartTest extends TestCase
{
    private TestStore $store;

    private int $locationId;

    private int $variantId;

    private int $wholesaleListId;

    protected function setUp(): void
    {
        $this->store = TestStore::create();
        $this->store->seedGroups();
        $this->locationId = (int) $this->store->seedLocation()['id'];
        $this->variantId = (int) $this->store->seedProduct($this->locationId, 500000, 20)['variant']['id'];
        $this->wholesaleListId = (int) $this->store->groupNamed('Wholesale')['price_list_id'];
    }

    public function testAGuestCartShowsTheRetailPriceAndTheRealStock(): void
    {
        $cart = $this->cart('guest-a');
        $summary = $this->store->cart->add($cart, ['variant_id' => $this->variantId, 'qty' => 2], null);

        self::assertSame(2, $summary['item_count']);
        self::assertSame(1000000, $summary['subtotal_kobo']);
        self::assertFalse($summary['has_errors']);

        $line = $summary['lines'][0];
        self::assertSame(500000, $line['unit_price_kobo']);
        self::assertSame(20, $line['available']);
        self::assertSame('Gold hoop earrings ' . substr($this->store->clock->nowString(), -4), $line['product_name']);
    }

    public function testAWholesaleCartIsPricedFromTheWholesaleList(): void
    {
        $this->store->setPrice($this->wholesaleListId, $this->variantId, 400000);
        $customer = $this->store->wholesaleCustomer();

        $summary = $this->store->cart->add(
            $this->cart('wholesale-a'),
            ['variant_id' => $this->variantId, 'qty' => 3],
            $customer
        );

        self::assertSame(400000, $summary['lines'][0]['unit_price_kobo']);
        self::assertSame(1200000, $summary['subtotal_kobo']);
    }

    public function testRaisingTheQuantityRepricesTheLineAtTheNextTier(): void
    {
        $this->store->setPrice($this->wholesaleListId, $this->variantId, 450000, 3);
        $customer = $this->store->wholesaleCustomer();
        $cart = $this->cart('tier-a');

        $this->store->cart->add($cart, ['variant_id' => $this->variantId, 'qty' => 1], $customer);
        $summary = $this->store->cart->setQty($cart, (int) $this->store->cart->summary($cart, $customer)['lines'][0]['id'], 3, $customer);

        self::assertSame(3, $summary['item_count']);
        self::assertSame(450000, $summary['lines'][0]['unit_price_kobo']);
        self::assertSame(1350000, $summary['subtotal_kobo']);
    }

    public function testAddingMoreThanTheLedgerHoldsIsRefusedAndNothingChanges(): void
    {
        $cart = $this->cart('stock-a');

        try {
            $this->store->cart->add($cart, ['variant_id' => $this->variantId, 'qty' => 21], null);
            self::fail('Expected the cart to refuse more than is available.');
        } catch (ValidationFailed $error) {
            self::assertStringContainsString('Only 20 left', $error->getMessage());
        }

        self::assertSame(0, $this->store->cart->lineCount($cart));
    }

    public function testAddingTheSameVariantAgainIncrementsItsLine(): void
    {
        $cart = $this->cart('merge-a');

        $this->store->cart->add($cart, ['variant_id' => $this->variantId, 'qty' => 2], null);
        $summary = $this->store->cart->add($cart, ['variant_id' => $this->variantId, 'qty' => 3], null);

        self::assertCount(1, $summary['lines']);
        self::assertSame(5, $summary['item_count']);
        self::assertSame(2500000, $summary['subtotal_kobo']);
    }

    public function testArchivingAProductMarksTheLineAsUnavailable(): void
    {
        $product = $this->store->seedProduct($this->locationId);
        $cart = $this->cart('archived-a');
        $this->store->cart->add($cart, ['variant_id' => (int) $product['variant']['id'], 'qty' => 1], null);

        $this->store->catalog->archive((int) $product['product']['id'], $this->store->actor());

        $summary = $this->store->cart->summary($cart, null);
        self::assertTrue($summary['has_errors']);
        self::assertSame('No longer available', $summary['lines'][0]['error']);

        try {
            $this->store->cart->add($cart, ['variant_id' => (int) $product['variant']['id'], 'qty' => 1], null);
            self::fail('An archived product must not be addable.');
        } catch (ValidationFailed $error) {
            self::assertStringContainsString('no longer available', $error->getMessage());
        }
    }

    public function testAProductSoldOutAfterItWasAddedFlagsTheCart(): void
    {
        $cart = $this->cart('soldout-a');
        $this->store->cart->add($cart, ['variant_id' => $this->variantId, 'qty' => 1], null);

        // Someone else buys the whole shelf.
        $this->store->orders->create([
            'channel' => 'other',
            'idempotency_key' => 'sell-the-shelf',
            'lines' => [['variant_id' => $this->variantId, 'qty' => 20]],
        ], $this->store->actor());

        $summary = $this->store->cart->summary($cart, null);

        self::assertTrue($summary['has_errors']);
        self::assertSame('Out of stock', $summary['lines'][0]['error']);
        self::assertSame(0, $summary['lines'][0]['available']);
    }

    public function testTwoVisitorsHaveTwoIndependentCarts(): void
    {
        $first = $this->cart('visitor-a');
        $second = $this->cart('visitor-b');

        $this->store->cart->add($first, ['variant_id' => $this->variantId, 'qty' => 2], null);
        $this->store->cart->add($second, ['variant_id' => $this->variantId, 'qty' => 1], null);

        self::assertSame(2, $this->store->cart->lineCount($first));
        self::assertSame(1, $this->store->cart->lineCount($second));
        self::assertNotSame((int) $first['id'], (int) $second['id']);
    }

    public function testRemovingAndClearingRemoveTheLines(): void
    {
        $cart = $this->cart('remove-a');
        $summary = $this->store->cart->add($cart, ['variant_id' => $this->variantId, 'qty' => 2], null);

        $summary = $this->store->cart->remove($cart, (int) $summary['lines'][0]['id'], null);
        self::assertSame([], $summary['lines']);
        self::assertSame(0, $summary['subtotal_kobo']);

        $this->store->cart->add($cart, ['variant_id' => $this->variantId, 'qty' => 2], null);
        $this->store->cart->clear($cart);
        self::assertSame(0, $this->store->cart->lineCount($cart));
    }

    public function testAConvertedCartReopensAsANewCartInsteadOfReusingItsOrder(): void
    {
        $cart = $this->cart('converted-a');
        $this->store->cart->add($cart, ['variant_id' => $this->variantId, 'qty' => 1], null);
        $this->store->cart->markConverted($cart, 999);

        $reopened = $this->store->cart->add($this->store->cart->findAny(hash('sha256', 'converted-a')), [
            'variant_id' => $this->variantId,
            'qty' => 2,
        ], null);

        self::assertSame('active', $reopened['status']);
        self::assertNotSame((string) $cart['uuid'], (string) $reopened['uuid'], 'A new uuid avoids replaying the old order');
        self::assertSame(2, $reopened['item_count']);
        self::assertSame(1000000, $reopened['subtotal_kobo']);

        $row = $this->store->cart->findAny(hash('sha256', 'converted-a'));
        self::assertNull($row['converted_order_id']);
    }

    /** @return array<string,mixed> */
    private function cart(string $token): array
    {
        return $this->store->cart->open(hash('sha256', $token));
    }
}
