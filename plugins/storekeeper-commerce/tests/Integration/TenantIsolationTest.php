<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Catalog\CatalogRepository;
use StoreKeeper\Commerce\Catalog\CatalogService;
use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Database\PdoConnection;
use StoreKeeper\Commerce\Core\FrozenClock;
use StoreKeeper\Commerce\Core\Hooks\InMemoryHooks;
use StoreKeeper\Commerce\Core\SchemaMigrator;
use StoreKeeper\Commerce\Inventory\InventoryRepository;
use StoreKeeper\Commerce\Inventory\InventoryService;
use StoreKeeper\Commerce\Pricing\PricingRepository;
use StoreKeeper\Commerce\Tests\Support\TestDatabase;
use StoreKeeper\Commerce\Tests\Support\TestStore;

/**
 * §6.4 / §21.3: one store's rows must never be visible or writable from
 * another store. Each tenant is a separate table prefix, as in multisite.
 */
final class TenantIsolationTest extends TestCase
{
    public function testTwoTenantsCannotSeeEachOthersData(): void
    {
        $tenantA = TestStore::create();

        $tenantBConnection = new PdoConnection(
            TestDatabase::pdo(),
            'wp_2_storekeeper_',
            TestDatabase::DEFAULT_NETWORK_PREFIX
        );
        (new SchemaMigrator($tenantBConnection, dirname(__DIR__, 2) . '/migrations'))->migrateAll();

        $clock = new FrozenClock();
        $hooks = new InMemoryHooks();
        $catalogA = $tenantA->catalog;
        $inventoryA = $tenantA->inventory;
        $locationA = $tenantA->seedLocation('Tenant A shop');
        $productA = $tenantA->seedProduct((int) $locationA['id'], 500000, 5, ['name' => 'Tenant A ring']);

        $repositoryB = new CatalogRepository($tenantBConnection, $clock);
        $pricingB = new PricingRepository($tenantBConnection, $clock);
        $inventoryB = new InventoryService(
            $tenantBConnection,
            new InventoryRepository($tenantBConnection, $clock),
            $hooks,
            $clock,
            new \StoreKeeper\Commerce\Alerts\AlertService($tenantBConnection, $clock)
        );
        $catalogB = new CatalogService($tenantBConnection, $repositoryB, $inventoryB, $pricingB, $hooks, new \StoreKeeper\Commerce\Core\Audit\NullAuditLogger(), $clock);

        // Tenant B sees nothing of A.
        self::assertSame(0, (int) $tenantBConnection->scalar(
            sprintf('SELECT COUNT(*) FROM %sproducts', $tenantBConnection->prefix())
        ));
        self::assertSame([], $catalogB->search('Tenant A'));
        self::assertNull($repositoryB->productByUuid((string) $productA['product']['uuid']));

        // A product created in B is invisible to A.
        $locationBId = (int) (new InventoryRepository($tenantBConnection, $clock))->createLocation([
            'uuid' => \StoreKeeper\Commerce\Core\Uuid::v4(),
            'name' => 'Tenant B shop',
            'type' => 'shop',
            'sellable_online' => 1,
            'online_priority' => 1,
            'address' => null,
            'is_pickup_point' => 0,
            'is_active' => 1,
            'created_at' => $clock->nowString(),
        ]);
        $catalogB->createProduct([
            'name' => 'Tenant B bangle',
            'stock' => 3,
            'location_id' => $locationBId,
        ], Actor::staff(2));

        self::assertSame(1, (int) $tenantBConnection->scalar(
            sprintf('SELECT COUNT(*) FROM %sproducts', $tenantBConnection->prefix())
        ));
        self::assertSame(1, (int) $tenantA->db->scalar(
            sprintf('SELECT COUNT(*) FROM %sproducts', $tenantA->db->prefix())
        ));
        self::assertSame([], $catalogA->search('Tenant B bangle'));
        self::assertSame(5, $inventoryA->availableOnline((int) $productA['variant']['id']));
    }
}
