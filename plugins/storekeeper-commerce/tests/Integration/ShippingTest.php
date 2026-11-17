<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Shipping\ShippingService;
use StoreKeeper\Commerce\Tests\Support\TestStore;

/**
 * Zones decide where the shop delivers; methods decide the fee (§13.3). The
 * fee is always quoted from the method's config and the cart, never from the
 * browser.
 */
final class ShippingTest extends TestCase
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

    public function testTheFirstMatchingZoneWinsAndACatchAllCoversTherest(): void
    {
        $island = $this->zone('Lagos Island', [['state' => 'Lagos', 'lga' => 'Lagos Island']], 10);
        $lagos = $this->zone('Lagos mainland', [['state' => 'Lagos', 'lga' => null]], 20);
        $rest = $this->zone('Rest of Nigeria', [], 100);

        $this->method($island, 'Island rider', ['type' => 'flat', 'config' => ['amount_kobo' => 100000]]);
        $this->method($lagos, 'Lagos rider', ['type' => 'flat', 'config' => ['amount_kobo' => 200000]]);
        $this->method($rest, 'Interstate park', ['type' => 'flat', 'config' => ['amount_kobo' => 350000]]);

        self::assertSame((int) $island['id'], (int) $this->store->shipping->resolveZone('Lagos', 'Lagos Island')['id']);
        self::assertSame((int) $lagos['id'], (int) $this->store->shipping->resolveZone('Lagos', 'Ikeja')['id']);
        self::assertSame((int) $rest['id'], (int) $this->store->shipping->resolveZone('Kano', 'Kano Municipal')['id']);
        self::assertNull($this->store->shipping->resolveZone(null, null), 'No address means no delivery zone');
    }

    public function testEachMethodTypeQuotesItsFee(): void
    {
        $zone = $this->zone('Lagos', [['state' => 'Lagos', 'lga' => null]]);

        $flat = $this->method($zone, 'Rider', ['type' => 'flat', 'config' => ['amount_kobo' => 250000]]);
        $freeOver = $this->method($zone, 'Free over', [
            'type' => 'free_over',
            'config' => ['threshold_kobo' => 1000000, 'else_amount_kobo' => 300000],
        ]);
        $byWeight = $this->method($zone, 'By weight', [
            'type' => 'weight_band',
            'config' => [
                'bands' => [
                    ['max_grams' => 1000, 'amount_kobo' => 200000],
                    ['max_grams' => 5000, 'amount_kobo' => 350000],
                ],
                'over_per_kg_kobo' => 50000,
            ],
        ]);
        $quoteLater = $this->method($zone, 'Quote later', [
            'type' => 'quote_later',
            'config' => ['message' => 'We will send the delivery fee.'],
        ]);
        $pickup = $this->method(null, 'Pickup', [
            'type' => 'pickup',
            'config' => ['instructions' => 'Shop 12, Balogun market'],
        ]);

        self::assertSame(250000, $this->store->shipping->quote($flat, 500000, 0)['amount_kobo']);
        self::assertSame(300000, $this->store->shipping->quote($freeOver, 500000, 0)['amount_kobo']);
        self::assertSame(0, $this->store->shipping->quote($freeOver, 1000000, 0)['amount_kobo']);
        self::assertSame(200000, $this->store->shipping->quote($byWeight, 500000, 800)['amount_kobo']);
        self::assertSame(350000, $this->store->shipping->quote($byWeight, 500000, 4800)['amount_kobo']);
        self::assertSame(450000, $this->store->shipping->quote($byWeight, 500000, 7000)['amount_kobo'], 'Two kilos over the last band');
        self::assertSame(0, $this->store->shipping->quote($quoteLater, 500000, 0)['amount_kobo']);
        self::assertTrue($this->store->shipping->quote($quoteLater, 500000, 0)['quoted_later']);
        self::assertSame(0, $this->store->shipping->quote($pickup, 500000, 0)['amount_kobo']);
        self::assertStringContainsString('Balogun', (string) $this->store->shipping->quote($pickup, 0, 0)['description']);
    }

    public function testAPickupMethodCanBeRestrictedToAGroup(): void
    {
        $wholesale = $this->store->groupNamed('Wholesale');
        $retail = $this->store->groupNamed('Retail');

        $this->method(null, 'Wholesale pickup', [
            'type' => 'pickup',
            'config' => [],
            'group_ids' => [(int) $wholesale['id']],
        ]);

        $forWholesale = $this->store->shipping->options(null, null, (int) $wholesale['id'], 0, 0);
        $forRetail = $this->store->shipping->options(null, null, (int) $retail['id'], 0, 0);

        self::assertCount(1, $forWholesale);
        self::assertSame([], $forRetail);
    }

    public function testOptionsOnlyIncludeAMatchedZonesMethodsAndAlwaysPickup(): void
    {
        $lagos = $this->zone('Lagos', [['state' => 'Lagos', 'lga' => null]]);
        $kano = $this->zone('Kano', [['state' => 'Kano', 'lga' => null]]);

        $this->method($lagos, 'Lagos rider', ['type' => 'flat', 'config' => ['amount_kobo' => 150000]]);
        $this->method($kano, 'Kano rider', ['type' => 'flat', 'config' => ['amount_kobo' => 400000]]);
        $this->method(null, 'Pick up at the shop', ['type' => 'pickup', 'config' => []]);

        $labels = array_column($this->store->shipping->options('Lagos', 'Ikeja', null, 0, 0), 'label');

        self::assertContains('Pick up at the shop', $labels);
        self::assertContains('Lagos rider', $labels);
        self::assertNotContains('Kano rider', $labels);
    }

    public function testCheckoutUsesTheZonesMethodAndStoresItOnTheOrder(): void
    {
        $zone = $this->zone('Lagos', [['state' => 'Lagos', 'lga' => null]]);
        $method = $this->method($zone, 'Same-day rider', ['type' => 'flat', 'config' => ['amount_kobo' => 250000]]);
        $token = $this->cartWith(2);

        $result = $this->store->checkout->place([
            'name' => 'Ada Obi',
            'phone' => '08031234567',
            'shipping' => 'method:' . $method['id'],
            'state' => 'Lagos',
            'lga' => 'Ikeja',
            'line1' => '12 Allen Avenue',
            'payment_method' => 'bank_transfer',
        ], $token, null);

        $order = $result['order'];
        self::assertSame(250000, (int) $order['shipping_kobo']);
        self::assertSame((int) $method['id'], (int) $order['shipping_method_id']);
        self::assertSame(1250000, (int) $order['total_kobo']);

        $address = json_decode((string) $order['shipping_address'], true);
        self::assertSame('delivery', $address['method']);
        self::assertSame('Same-day rider', $address['shipping_label']);
        self::assertSame('method:' . $method['id'], $address['shipping_option']);
    }

    public function testAMethodFromAnotherZoneIsRefused(): void
    {
        $lagos = $this->zone('Lagos', [['state' => 'Lagos', 'lga' => null]]);
        $kano = $this->zone('Kano', [['state' => 'Kano', 'lga' => null]]);
        $this->method($lagos, 'Lagos rider', ['type' => 'flat', 'config' => ['amount_kobo' => 150000]]);
        $kanoMethod = $this->method($kano, 'Kano rider', ['type' => 'flat', 'config' => ['amount_kobo' => 400000]]);

        $token = $this->cartWith(1);

        try {
            $this->store->checkout->place([
                'name' => 'Ada Obi',
                'phone' => '08031234567',
                'shipping' => 'method:' . $kanoMethod['id'],
                'state' => 'Lagos',
                'lga' => 'Ikeja',
                'line1' => '12 Allen Avenue',
                'payment_method' => 'bank_transfer',
            ], $token, null);
            self::fail('Expected a method outside the address zone to be refused.');
        } catch (ValidationFailed $error) {
            self::assertArrayHasKey('shipping', $error->context()['errors']);
        }

        self::assertSame(0, (int) $this->store->db->scalar(
            sprintf('SELECT COUNT(*) FROM %sorders', $this->store->db->prefix())
        ));
    }

    public function testQuoteLaterChargesNothingNowAndSaysSo(): void
    {
        $zone = $this->zone('Lagos', [['state' => 'Lagos', 'lga' => null]]);
        $method = $this->method($zone, 'We will quote', [
            'type' => 'quote_later',
            'config' => ['message' => 'We will send the delivery fee on WhatsApp.'],
        ]);

        $token = $this->cartWith(1);
        $result = $this->store->checkout->place([
            'name' => 'Ada Obi',
            'phone' => '08031234567',
            'shipping' => 'method:' . $method['id'],
            'state' => 'Lagos',
            'lga' => 'Ikeja',
            'line1' => '12 Allen Avenue',
            'payment_method' => 'bank_transfer',
        ], $token, null);

        self::assertSame(0, (int) $result['order']['shipping_kobo']);
        $address = json_decode((string) $result['order']['shipping_address'], true);
        self::assertTrue($address['quote_later'] ?? false);
    }

    public function testDeliveryNeedsAnAddressEvenWhenAZoneMethodExists(): void
    {
        $zone = $this->zone('Lagos', [['state' => 'Lagos', 'lga' => null]]);
        $method = $this->method($zone, 'Rider', ['type' => 'flat', 'config' => ['amount_kobo' => 150000]]);
        $token = $this->cartWith(1);

        $this->expectException(ValidationFailed::class);

        $this->store->checkout->place([
            'name' => 'Ada Obi',
            'phone' => '08031234567',
            'shipping' => 'method:' . $method['id'],
            'state' => 'Lagos',
            'lga' => 'Ikeja',
            'payment_method' => 'bank_transfer',
        ], $token, null);
    }

    public function testAnIncompleteMethodConfigurationIsRefused(): void
    {
        $zone = $this->zone('Lagos', [['state' => 'Lagos', 'lga' => null]]);

        $this->expectException(ValidationFailed::class);

        $this->store->shipping->createMethod([
            'zone_id' => (int) $zone['id'],
            'name' => 'Broken weight',
            'type' => 'weight_band',
            'config' => ['bands' => []],
        ], $this->store->actor());
    }

    public function testSeedingADefaultZoneAndPickupIsIdempotent(): void
    {
        $first = $this->store->shipping->seedDefaults($this->store->actor());
        $second = $this->store->shipping->seedDefaults($this->store->actor());

        self::assertNotNull($first['zone_id']);
        self::assertNotNull($first['method_id']);
        self::assertNull($second['zone_id'], 'The zone is only created once');
        self::assertNull($second['method_id'], 'The pickup method is only created once');
        self::assertCount(1, $this->store->shipping->zones());
        self::assertCount(1, $this->store->shipping->methods());
        self::assertSame(ShippingService::PICKUP, $this->store->shipping->methods()[0]['type']);
    }

    /** @param list<array{state:string,lga:?string}> $areas */
    private function zone(string $name, array $areas, int $sortOrder = 10): array
    {
        return $this->store->shipping->createZone([
            'name' => $name,
            'areas' => $areas,
            'sort_order' => $sortOrder,
        ], $this->store->actor());
    }

    /** @param array<string,mixed> $overrides */
    private function method(?array $zone, string $name, array $overrides): array
    {
        return $this->store->shipping->createMethod([
            'zone_id' => $zone === null ? null : (int) $zone['id'],
            'name' => $name,
            ...$overrides,
        ], $this->store->actor());
    }

    private function cartWith(int $qty): string
    {
        $token = hash('sha256', uniqid('cart', true));
        $cart = $this->store->cart->open($token);
        $this->store->cart->add($cart, ['variant_id' => $this->variantId, 'qty' => $qty], null);

        return $token;
    }
}
