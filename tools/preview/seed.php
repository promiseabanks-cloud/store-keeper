<?php

use StoreKeeper\Commerce\Catalog\CatalogService;
use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Database\Connection;
use StoreKeeper\Commerce\Core\Plugin;
use StoreKeeper\Commerce\Core\Uuid;
use StoreKeeper\Commerce\Customers\CustomerService;
use StoreKeeper\Commerce\Inventory\InventoryRepository;
use StoreKeeper\Commerce\Inventory\InventoryService;
use StoreKeeper\Commerce\Pricing\PricingService;

/**
 * Seed one merchant store with a realistic catalog, groups, prices and stock.
 *
 *   wp eval-file tools/preview/seed.php            # uses SK_SEED_BLOG (default 2)
 *
 * Idempotent: does nothing when the store already has products.
 */

if (!defined('ABSPATH')) {
    exit(1);
}

$blogId = (int) (getenv('SK_SEED_BLOG') ?: 2);
switch_to_blog($blogId);
Plugin::reset();

/** @var Connection $db */
$db = Plugin::container()->get(Connection::class);
$catalog = Plugin::container()->get(CatalogService::class);
$customers = Plugin::container()->get(CustomerService::class);
$inventory = Plugin::container()->get(InventoryService::class);
$inventoryRepository = Plugin::container()->get(InventoryRepository::class);
$pricing = Plugin::container()->get(PricingService::class);

$actor = Actor::staff(1);

$existing = (int) $db->scalar('SELECT COUNT(*) FROM ' . $db->prefix() . 'products');
if ($existing > 0) {
    WP_CLI::log(sprintf('Store %d already has %d product(s); nothing to seed.', $blogId, $existing));
    restore_current_blog();

    return;
}

$groups = $customers->seedDefaultGroups();
$wholesale = null;
foreach ($groups as $group) {
    if ($group['name'] === 'Wholesale') {
        $wholesale = $group;
    }
}

$locationId = $inventoryRepository->createLocation([
    'uuid' => Uuid::v4(),
    'name' => 'Balogun shop',
    'type' => 'shop',
    'sellable_online' => 1,
    'online_priority' => 1,
    'address' => 'Shop 12, Balogun market, Lagos Island',
    'is_pickup_point' => 1,
    'is_active' => 1,
    'created_at' => gmdate('Y-m-d H:i:s.v'),
]);

$catalogData = [
    [
        'name' => 'Gold hoop earrings',
        'description' => 'Lightweight gold-plated hoops. Hypoallergenic posts, sold per pair.',
        'price' => 850000,
        'wholesale' => 620000,
        'wholesale_min' => 12,
        'stock' => 60,
        'options' => [[
            'name' => 'Colour',
            'display' => 'color',
            'values' => [
                ['value' => 'Gold', 'color_hex' => '#d4af37'],
                ['value' => 'Silver', 'color_hex' => '#c0c0c0'],
                ['value' => 'Rose gold', 'color_hex' => '#b76e79'],
            ],
        ]],
    ],
    [
        'name' => 'Bone straight wig 20 inch',
        'description' => '100% human hair, 20 inches, 250g. Includes adjustable band.',
        'price' => 8500000,
        'wholesale' => 7200000,
        'wholesale_min' => 3,
        'stock' => 25,
        'options' => [[
            'name' => 'Colour',
            'display' => 'color',
            'values' => [
                ['value' => 'Natural black', 'color_hex' => '#1c1c1c'],
                ['value' => 'Dark brown', 'color_hex' => '#4b2e2e'],
            ],
        ]],
    ],
    [
        'name' => 'Ankara fabric (6 yards)',
        'description' => 'Crisp cotton wax print, six-yard bundle. Colour-fast.',
        'price' => 1200000,
        'wholesale' => 950000,
        'wholesale_min' => 6,
        'stock' => 40,
        'options' => [[
            'name' => 'Print',
            'display' => 'label',
            'values' => [
                ['value' => 'Amber'],
                ['value' => 'Indigo'],
            ],
        ]],
    ],
];

$variantCount = 0;

foreach ($catalogData as $item) {
    $result = $catalog->createProduct([
        'name' => $item['name'],
        'description' => $item['description'],
        'status' => 'active',
        'price_kobo' => $item['price'],
        'options' => $item['options'],
    ], $actor);

    foreach ($result['variants'] as $variant) {
        $variantId = (int) $variant['id'];
        $inventory->opening($variantId, $locationId, $item['stock'], $actor, 'Preview seed');

        if ($wholesale !== null) {
            $pricing->setPrice(
                (int) $wholesale['price_list_id'],
                $variantId,
                null,
                $item['wholesale_min'],
                new \StoreKeeper\Commerce\Core\Money($item['wholesale']),
                $actor
            );
        }

        $variantCount++;
    }
}

$customers->create([
    'name' => 'Ada Obi',
    'business_name' => 'Ada Fabrics',
    'phone' => '08031234567',
    'group_id' => $wholesale['id'] ?? null,
    'email' => 'ada@example.test',
], $actor, 'storefront');

WP_CLI::success(sprintf(
    'Seeded store %d: %d products, %d variants, %d customer groups, 1 location.',
    $blogId,
    count($catalogData),
    $variantCount,
    count($groups)
));

restore_current_blog();
