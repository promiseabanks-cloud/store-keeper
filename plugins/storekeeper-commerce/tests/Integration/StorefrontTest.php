<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Storefront\CatalogView;
use StoreKeeper\Commerce\Storefront\CustomerContext;
use StoreKeeper\Commerce\Tests\Support\TestStore;

/**
 * What the storefront reads: which products a visitor sees, the price their group
 * pays, and the options, photos and stock behind a product page (§11.4, §11.5).
 */
final class StorefrontTest extends TestCase
{
    private TestStore $store;

    private int $locationId;

    private int $retailListId;

    private int $wholesaleListId;

    /** @var array<string,mixed> */
    private array $category;

    protected function setUp(): void
    {
        $this->store = TestStore::create();
        $this->store->seedGroups();
        $this->locationId = (int) $this->store->seedLocation()['id'];

        $retail = $this->store->pricingRepository->basePriceList();
        self::assertNotNull($retail);
        $this->retailListId = (int) $retail['id'];
        $this->wholesaleListId = (int) $this->store->groupNamed('Wholesale')['price_list_id'];

        $this->category = $this->store->catalog->createCategory(['name' => 'Earrings'], $this->store->actor());
    }

    public function testTheShopShowsOnlySellableProductsAtTheRetailPrice(): void
    {
        $this->seedProduct();
        $this->seedProduct(['name' => 'Draft hoops', 'status' => 'draft']);
        $this->seedProduct(['name' => 'Hidden hoops', 'visibility' => 'hidden']);

        $grid = $this->view()->grid();

        self::assertSame(1, $grid['total'], 'Drafts and hidden pieces are not for sale');
        self::assertCount(1, $grid['items']);

        $card = $grid['items'][0];
        self::assertSame('Gold hoop earrings', $card['name']);
        self::assertSame(100000, $card['price']['kobo']);
        self::assertSame('₦1,000', $card['price']['label']);
        self::assertFalse($card['price']['is_range']);
        self::assertSame(5, $card['available'], 'Only the sellable location is counted');
        self::assertTrue($card['in_stock']);
        self::assertCount(2, $card['swatches'], 'The colour option becomes card swatches');
        self::assertSame('#d4af37', $card['swatches'][0]['color_hex']);
        self::assertSame('https://shop.test/product/gold-hoop-earrings/', $card['url']);
    }

    public function testAWholesaleCustomerSeesTheirGroupPriceEverywhere(): void
    {
        $this->seedProduct();
        $customer = $this->store->wholesaleCustomer();

        $guest = $this->view()->grid()['items'][0];
        $wholesale = $this->view($customer)->grid()['items'][0];

        self::assertSame(100000, $guest['price']['kobo']);
        self::assertSame(70000, $wholesale['price']['kobo'], 'A wholesale list from 12 pieces prices the card at that tier');
        self::assertSame('₦700', $wholesale['price']['label']);

        $page = $this->view($customer)->product('gold-hoop-earrings');
        self::assertNotNull($page);
        self::assertSame(70000, $page['price']['amount_kobo']);
        self::assertSame('group_tier', $page['price']['source']);
    }

    public function testTheProductPageListsOptionsAndPricesEachVariant(): void
    {
        $this->seedProduct();

        $page = $this->view()->product('gold-hoop-earrings');
        self::assertNotNull($page);

        self::assertSame('Colour', $page['options'][0]['name']);
        self::assertSame('color', $page['options'][0]['display']);
        self::assertTrue($page['options'][0]['is_media']);
        self::assertCount(2, $page['options'][0]['values']);

        // The default selection is the first combination that is actually in stock.
        self::assertSame(['colour' => 'gold'], $page['selection']);
        self::assertSame(100000, $page['price']['amount_kobo']);
        self::assertSame(5, $page['available']);
        self::assertTrue($page['in_stock']);

        self::assertCount(2, $page['variants']);
    }

    public function testChoosingASwatchPicksThatVariantAndItsStock(): void
    {
        $this->seedProduct();

        $silver = $this->view()->product('gold-hoop-earrings', ['colour' => 'silver']);
        self::assertNotNull($silver);
        self::assertSame(['colour' => 'silver'], $silver['selection']);
        self::assertSame(0, $silver['available']);
        self::assertFalse($silver['in_stock']);
        self::assertSame('Out of stock', $silver['stock_label']);
    }

    public function testACategoryAndASearchNarrowTheShop(): void
    {
        $this->seedProduct();
        $this->seedProduct(['name' => 'Ankara fabric', 'category_id' => null]);
        $other = $this->store->catalog->createCategory(['name' => 'Fabric'], $this->store->actor());
        $this->store->catalog->createProduct([
            'name' => 'Ankara wax print',
            'status' => 'active',
            'price_kobo' => 50000,
            'category_id' => (int) $other['id'],
        ], $this->store->actor());

        $view = $this->view();
        self::assertSame(3, $view->grid()['total']);
        self::assertSame(1, $view->grid('', (int) $this->category['id'])['total']);
        self::assertSame(2, $view->grid('ankara')['total']);
        self::assertSame(1, $view->grid('hoop')['total']);
        self::assertSame(0, $view->grid('nothing like this')['total']);
    }

    public function testTheGridTakesTheCheapestSingleUnitPriceAcrossVariants(): void
    {
        $this->seedProduct();

        // A second product whose two colours are priced differently shows "from".
        $result = $this->store->catalog->createProduct([
            'name' => 'Twisted hoops',
            'status' => 'active',
            'price_kobo' => 120000,
            'options' => [[
                'name' => 'Colour',
                'display' => 'color',
                'values' => [['value' => 'Gold', 'color_hex' => '#d4af37'], ['value' => 'Silver', 'color_hex' => '#c0c0c0']],
            ]],
        ], $this->store->actor());

        $variants = $result['variants'];
        $this->store->setPrice($this->retailListId, (int) $variants[1]['id'], 90000);

        $grid = $this->view()->grid();
        $cards = [];
        foreach ($grid['items'] as $item) {
            $cards[$item['name']] = $item;
        }

        self::assertSame(90000, $cards['Twisted hoops']['price']['kobo']);
        self::assertTrue($cards['Twisted hoops']['price']['is_range']);
        self::assertSame('₦900', $cards['Twisted hoops']['price']['label']);
    }

    public function testAnUnknownOrInvisibleProductIsNotServed(): void
    {
        $this->seedProduct(['name' => 'Hidden hoops', 'visibility' => 'hidden']);

        self::assertNull($this->view()->product('hidden-hoops'));
        self::assertNull($this->view()->product('gold-hoop-earrings'));
    }

    /**
     * @param array<string,mixed>|null $customer
     */
    private function view(?array $customer = null): CatalogView
    {
        return new CatalogView(
            $this->store->catalogRepository,
            $this->store->pricing,
            $this->store->pricingRepository,
            $this->store->inventory,
            $this->store->db,
            new CustomerContext($customer === null ? null : static fn (): array => $customer),
        );
    }

    /** @param array<string,mixed> $overrides */
    private function seedProduct(array $overrides = []): array
    {
        $result = $this->store->catalog->createProduct([
            'name' => 'Gold hoop earrings',
            'status' => 'active',
            'price_kobo' => 100000,
            'category_id' => (int) $this->category['id'],
            'options' => [[
                'name' => 'Colour',
                'display' => 'color',
                'values' => [
                    ['value' => 'Gold', 'color_hex' => '#d4af37'],
                    ['value' => 'Silver', 'color_hex' => '#c0c0c0'],
                ],
            ]],
            ...$overrides,
        ], $this->store->actor());

        foreach ($result['variants'] as $index => $variant) {
            $variantId = (int) $variant['id'];
            $this->store->inventory->opening($variantId, $this->locationId, $index === 0 ? 5 : 0, $this->store->actor(), 'Opening');
            $this->store->setPrice($this->wholesaleListId, $variantId, 70000, 12);
        }

        return $result;
    }
}
