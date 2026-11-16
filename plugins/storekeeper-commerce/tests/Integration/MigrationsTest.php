<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Tests\Support\TestDatabase;
use StoreKeeper\Commerce\Tests\Support\TestStore;

final class MigrationsTest extends TestCase
{
    public function testEveryStoreTableFromTheBriefExists(): void
    {
        $store = TestStore::create();

        $expected = [
            // §8.1 catalog
            'categories', 'products', 'options', 'option_values', 'product_options', 'variants',
            'variant_option_values', 'product_media', 'pack_units',
            // §8.2 pricing and customers
            'price_lists', 'prices', 'customer_groups', 'customers', 'customer_prices', 'addresses',
            'customer_sessions', 'otp_codes',
            // §8.3 inventory
            'locations', 'stock_levels', 'stock_movements', 'stock_receipts', 'stock_transfers',
            'stock_transfer_lines', 'stock_counts', 'stock_count_lines',
            // §8.4 orders and fulfilment
            'orders', 'order_items', 'payments', 'refunds', 'shipping_zones', 'shipping_zone_areas',
            'shipping_methods', 'shipments', 'shipment_items', 'shipment_events',
            // §8.5 devices and system
            'devices', 'sync_batches', 'idempotency_keys', 'message_templates', 'message_log',
            'alerts', 'audit_log', 'imports', 'sequences',
        ];

        foreach ($expected as $table) {
            self::assertTrue(
                TestDatabase::tableExists($store->db, $store->db->prefix() . $table),
                sprintf('Missing store table %s%s', $store->db->prefix(), $table)
            );
        }
    }

    public function testEveryNetworkTableFromTheBriefExists(): void
    {
        $store = TestStore::create();

        $expected = [
            'plans', 'applications', 'tenants', 'subscriptions', 'platform_invoices',
            'usage_counters', 'service_requests', 'platform_audit_log',
        ];

        foreach ($expected as $table) {
            self::assertTrue(
                TestDatabase::tableExists($store->db, $store->db->networkPrefix() . $table),
                sprintf('Missing network table %s%s', $store->db->networkPrefix(), $table)
            );
        }
    }

    public function testMigrationsAreRecordedAndNotRerun(): void
    {
        $store = TestStore::create();
        $first = $store->db->select(sprintf('SELECT migration FROM %sschema_migrations', $store->db->prefix()));
        self::assertCount(7, $first);

        // Running the migrator again is a no-op, as it is on plugin activation.
        $again = (new \StoreKeeper\Commerce\Core\SchemaMigrator($store->db, dirname(__DIR__, 2) . '/migrations'))->migrateAll();
        self::assertSame([], $again['store']);
        self::assertSame([], $again['network']);
    }

    public function testStockLedgerColumnsMatchTheSpecification(): void
    {
        $store = TestStore::create();
        $columns = $store->db->select(sprintf('SHOW COLUMNS FROM %sstock_movements', $store->db->prefix()));
        $names = array_column($columns, 'Field');

        foreach (['on_hand_delta', 'reserved_delta', 'order_id', 'order_item_id', 'client_created_at', 'actor_type'] as $column) {
            self::assertContains($column, $names);
        }
    }
}
