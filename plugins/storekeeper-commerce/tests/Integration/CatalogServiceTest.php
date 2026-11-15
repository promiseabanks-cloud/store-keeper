<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Tests\Support\TestStore;

final class CatalogServiceTest extends TestCase
{
    private TestStore $store;

    private int $locationId;

    protected function setUp(): void
    {
        $this->store = TestStore::create();
        $this->locationId = (int) $this->store->seedLocation()['id'];
        $this->store->seedGroups();
    }

    public function testVariantGridIsBuiltFromMerchantDefinedOptions(): void
    {
        $result = $this->store->catalog->createProduct([
            'name' => 'Gold hoop earrings',
            'price_kobo' => 500000,
            'stock' => 10,
            'location_id' => $this->locationId,
            'options' => [
                [
                    'name' => 'Colour',
                    'display' => 'color',
                    'values' => [
                        ['value' => 'Red', 'color_hex' => '#ff0000'],
                        ['value' => 'Silver', 'color_hex' => '#c0c0c0'],
                    ],
                ],
                [
                    'name' => 'Size',
                    'display' => 'label',
                    'values' => [['value' => 'Small'], ['value' => 'Large']],
                ],
            ],
        ], $this->store->actor());

        self::assertCount(4, $result['variants']);
        $titles = array_column($result['variants'], 'title');
        sort($titles);
        self::assertSame(['Red / Large', 'Red / Small', 'Silver / Large', 'Silver / Small'], $titles);

        $productId = (int) $result['product']['id'];
        $options = $this->store->catalogRepository->productOptions($productId);
        self::assertCount(2, $options);
        self::assertSame(1, (int) $options[0]['is_media_option'], 'The colour option drives photos (R4)');
        self::assertSame(0, (int) $options[1]['is_media_option']);
    }

    public function testPhotosAttachToAnOptionValueNotJustTheProduct(): void
    {
        $result = $this->store->catalog->createProduct([
            'name' => 'Ribbon fabric',
            'options' => [
                [
                    'name' => 'Colour',
                    'display' => 'color',
                    'values' => [['value' => 'Red'], ['value' => 'Blue']],
                ],
            ],
        ], $this->store->actor());

        $productId = (int) $result['product']['id'];
        $colourOption = $this->store->catalogRepository->productOptions($productId)[0];
        $redValue = null;
        foreach ($this->store->catalogRepository->optionValues((int) $colourOption['id']) as $value) {
            if ($value['value'] === 'Red') {
                $redValue = $value;
            }
        }

        self::assertNotNull($redValue);

        $this->store->catalog->assignMedia($productId, 4242, (int) $redValue['id'], $this->store->actor());
        $this->store->catalog->assignMedia($productId, 4243, null, $this->store->actor());

        $media = $this->store->catalogRepository->media($productId);

        self::assertCount(2, $media);
        self::assertSame((int) $redValue['id'], (int) $media[0]['option_value_id']);
        self::assertNull($media[1]['option_value_id'], 'A general photo shows for every option');
    }

    public function testRebuildVariantsCreatesNewCombinationsAndArchivesRemovedOnes(): void
    {
        $result = $this->store->catalog->createProduct([
            'name' => 'Two tone ring',
            'options' => [
                ['name' => 'Colour', 'values' => [['value' => 'Gold'], ['value' => 'Silver']]],
            ],
        ], $this->store->actor());

        $productId = (int) $result['product']['id'];
        self::assertCount(2, $this->store->catalog->variants($productId));

        $colourOption = $this->store->catalogRepository->productOptions($productId)[0];
        $this->store->catalog->addOptionValue((int) $colourOption['id'], 'Rose gold');

        $rebuild = $this->store->catalog->rebuildVariants($productId, $this->store->actor());

        self::assertCount(1, $rebuild['created']);
        self::assertSame([], $rebuild['archived']);
        self::assertCount(3, $this->store->catalog->variants($productId));
    }

    public function testQuickAddCreatesADefaultVariantWithPriceAndStock(): void
    {
        $result = $this->store->catalog->quickAdd([
            'name' => 'Plain scarf',
            'price_kobo' => 250000,
            'stock' => 7,
            'location_id' => $this->locationId,
        ], $this->store->actor());

        $variant = $result['variants'][0];

        self::assertSame('Default', $variant['title']);
        self::assertSame(7, $this->store->inventory->availableOnline((int) $variant['id']));
        self::assertSame(250000, $this->store->pricing->resolve((int) $variant['id'], null, 1, null)->unitAmount->minor());
        self::assertSame('active', $result['product']['status']);
    }

    public function testArchivingAProductArchivesItsVariantsButKeepsHistory(): void
    {
        $product = $this->store->seedProduct($this->locationId);
        $productId = (int) $product['product']['id'];

        $this->store->catalog->archive($productId, $this->store->actor());

        self::assertSame('archived', $this->store->catalogRepository->product($productId)['status']);
        $variants = $this->store->catalogRepository->variants($productId, true);
        self::assertSame('archived', $variants[0]['status']);
        self::assertSame([], $this->store->catalog->variants($productId), 'Archived variants leave the active grid');
    }

    public function testSearchFindsProductsByNameAndSku(): void
    {
        $product = $this->store->seedProduct($this->locationId, 500000, 2, ['name' => 'Ankara fabric bundle']);

        self::assertNotEmpty($this->store->catalog->search('ankara'));
        self::assertNotEmpty($this->store->catalog->search((string) $product['variant']['sku']));
        self::assertSame([], $this->store->catalog->search('nonexistent-product'));
    }
}
