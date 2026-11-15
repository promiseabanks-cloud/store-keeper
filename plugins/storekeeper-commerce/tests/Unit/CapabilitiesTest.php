<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Core\Capabilities;

final class CapabilitiesTest extends TestCase
{
    public function testEveryCapabilityIsPrefixed(): void
    {
        foreach (Capabilities::all() as $capability) {
            self::assertSame('storekeeper_' . $capability, Capabilities::wp($capability));
        }
    }

    public function testCapabilityNamesAreUnique(): void
    {
        $all = Capabilities::all();

        self::assertSame(count($all), count(array_unique($all)));
    }

    public function testOwnerCanDoEverything(): void
    {
        self::assertSame(Capabilities::all(), Capabilities::roles()[Capabilities::ROLE_OWNER]);
    }

    public function testRolesOnlyReferenceKnownCapabilities(): void
    {
        foreach (Capabilities::roles() as $role => $capabilities) {
            foreach ($capabilities as $capability) {
                self::assertContains(
                    $capability,
                    Capabilities::all(),
                    sprintf('Role %s references unknown capability %s', $role, $capability)
                );
            }
        }
    }

    public function testRoleSlugsArePrefixed(): void
    {
        self::assertSame('storekeeper_owner', Capabilities::roleSlug(Capabilities::ROLE_OWNER));
        self::assertSame('storekeeper_dispatcher', Capabilities::roleSlug(Capabilities::ROLE_DISPATCHER));
    }

    public function testThePermissionMatrixMatchesTheBrief(): void
    {
        $roles = Capabilities::roles();

        // Manager: everything except billing, staff and store settings.
        self::assertNotContains(Capabilities::MANAGE_STAFF, $roles[Capabilities::ROLE_MANAGER]);
        self::assertNotContains(Capabilities::MANAGE_SETTINGS, $roles[Capabilities::ROLE_MANAGER]);
        self::assertNotContains(Capabilities::VIEW_BILLING, $roles[Capabilities::ROLE_MANAGER]);
        self::assertContains(Capabilities::REFUND, $roles[Capabilities::ROLE_MANAGER]);

        // Sales: no prices, no revenue, no refunds.
        self::assertNotContains(Capabilities::EDIT_PRICES, $roles[Capabilities::ROLE_SALES]);
        self::assertNotContains(Capabilities::VIEW_REVENUE, $roles[Capabilities::ROLE_SALES]);
        self::assertNotContains(Capabilities::REFUND, $roles[Capabilities::ROLE_SALES]);
        self::assertContains(Capabilities::CREATE_ORDERS, $roles[Capabilities::ROLE_SALES]);

        // Uploader: products and stock only; no prices and no orders.
        self::assertContains(Capabilities::EDIT_PRODUCTS, $roles[Capabilities::ROLE_UPLOADER]);
        self::assertContains(Capabilities::RECEIVE_STOCK, $roles[Capabilities::ROLE_UPLOADER]);
        self::assertNotContains(Capabilities::EDIT_PRICES, $roles[Capabilities::ROLE_UPLOADER]);
        self::assertNotContains(Capabilities::CREATE_ORDERS, $roles[Capabilities::ROLE_UPLOADER]);
        self::assertNotContains(Capabilities::VIEW_REVENUE, $roles[Capabilities::ROLE_UPLOADER]);

        // Dispatcher: shipments only.
        self::assertSame([Capabilities::UPDATE_SHIPMENTS], $roles[Capabilities::ROLE_DISPATCHER]);
    }

    public function testRoleCapabilitiesIncludeRead(): void
    {
        $caps = Capabilities::roleCapabilities(Capabilities::ROLE_CASHIER);

        self::assertTrue($caps['read']);
        self::assertTrue($caps['storekeeper_sell_pos']);
        self::assertArrayNotHasKey('storekeeper_view_revenue', $caps);
    }

    public function testBrandConstantsDriveEveryPrefix(): void
    {
        self::assertSame('storekeeper_', Brand::TABLE_PREFIX);
        self::assertSame('storekeeper/', Brand::HOOK_PREFIX);
        self::assertSame('storekeeper/v1', Brand::REST_NAMESPACE);
        self::assertSame('storekeeper/v1', Brand::REST_NAMESPACE);
        self::assertSame('Store Keeper', Brand::NAME);
        self::assertSame('storekeeper_edit_prices', Brand::capability('edit_prices'));
    }
}
