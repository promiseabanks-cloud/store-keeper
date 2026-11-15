<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core;

/**
 * Store capabilities and the role matrix (§5.2).
 *
 * Capabilities are WordPress capabilities prefixed storekeeper_. Role slugs are
 * prefixed too, so no merchant role can collide with core WordPress roles.
 */
final class Capabilities
{
    public const VIEW_REVENUE = 'view_revenue';
    public const EDIT_PRODUCTS = 'edit_products';
    public const EDIT_PRICES = 'edit_prices';
    public const ARCHIVE_PRODUCTS = 'archive_products';
    public const RECEIVE_STOCK = 'receive_stock';
    public const ADJUST_STOCK = 'adjust_stock';
    public const TRANSFER_STOCK = 'transfer_stock';
    public const CREATE_ORDERS = 'create_orders';
    public const SELL_POS = 'sell_pos';
    public const APPLY_DISCOUNT = 'apply_discount';
    public const OVERRIDE_PRICE = 'override_price';
    public const CONFIRM_PAYMENTS = 'confirm_payments';
    public const CANCEL_ORDERS = 'cancel_orders';
    public const REFUND = 'refund';
    public const UPDATE_SHIPMENTS = 'update_shipments';
    public const MANAGE_CUSTOMERS = 'manage_customers';
    public const APPROVE_WHOLESALE = 'approve_wholesale';
    public const MANAGE_STAFF = 'manage_staff';
    public const MANAGE_SETTINGS = 'manage_settings';
    public const REGISTER_DEVICES = 'register_devices';
    public const VIEW_REPORTS = 'view_reports';
    public const RESOLVE_ALERTS = 'resolve_alerts';
    public const VIEW_AUDIT_LOG = 'view_audit_log';
    public const VIEW_BILLING = 'view_billing';

    public const ROLE_OWNER = 'owner';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_SALES = 'sales';
    public const ROLE_CASHIER = 'cashier';
    public const ROLE_UPLOADER = 'uploader';
    public const ROLE_DISPATCHER = 'dispatcher';

    /** @return list<string> every capability, unprefixed */
    public static function all(): array
    {
        return [
            self::VIEW_REVENUE,
            self::EDIT_PRODUCTS,
            self::EDIT_PRICES,
            self::ARCHIVE_PRODUCTS,
            self::RECEIVE_STOCK,
            self::ADJUST_STOCK,
            self::TRANSFER_STOCK,
            self::CREATE_ORDERS,
            self::SELL_POS,
            self::APPLY_DISCOUNT,
            self::OVERRIDE_PRICE,
            self::CONFIRM_PAYMENTS,
            self::CANCEL_ORDERS,
            self::REFUND,
            self::UPDATE_SHIPMENTS,
            self::MANAGE_CUSTOMERS,
            self::APPROVE_WHOLESALE,
            self::MANAGE_STAFF,
            self::MANAGE_SETTINGS,
            self::REGISTER_DEVICES,
            self::VIEW_REPORTS,
            self::RESOLVE_ALERTS,
            self::VIEW_AUDIT_LOG,
            self::VIEW_BILLING,
        ];
    }

    /** The WordPress capability string for an engine capability. */
    public static function wp(string $capability): string
    {
        return Brand::capability($capability);
    }

    /** The WordPress role slug for a store role. */
    public static function roleSlug(string $role): string
    {
        return Brand::CAPABILITY_PREFIX . $role;
    }

    /**
     * Role => capability list (§5.2). Limits such as "discount up to limit" and
     * "cancel own, unpaid only" are enforced by the services/settings, not by
     * different capabilities.
     *
     * @return array<string,list<string>>
     */
    public static function roles(): array
    {
        $all = self::all();

        return [
            self::ROLE_OWNER => $all,
            self::ROLE_MANAGER => array_values(array_diff($all, [
                self::MANAGE_STAFF,
                self::MANAGE_SETTINGS,
                self::VIEW_BILLING,
            ])),
            self::ROLE_SALES => [
                self::CREATE_ORDERS,
                self::SELL_POS,
                self::APPLY_DISCOUNT,
                self::CONFIRM_PAYMENTS,
                self::CANCEL_ORDERS,
                self::UPDATE_SHIPMENTS,
                self::MANAGE_CUSTOMERS,
                self::VIEW_REPORTS,
            ],
            self::ROLE_CASHIER => [
                self::CREATE_ORDERS,
                self::SELL_POS,
                self::APPLY_DISCOUNT,
                self::CONFIRM_PAYMENTS,
                self::CANCEL_ORDERS,
                self::MANAGE_CUSTOMERS,
                self::VIEW_REPORTS,
            ],
            self::ROLE_UPLOADER => [
                self::EDIT_PRODUCTS,
                self::RECEIVE_STOCK,
                self::ADJUST_STOCK,
                self::TRANSFER_STOCK,
                self::VIEW_REPORTS,
                self::RESOLVE_ALERTS,
            ],
            self::ROLE_DISPATCHER => [
                self::UPDATE_SHIPMENTS,
            ],
        ];
    }

    /** Human labels used when the roles are registered in WordPress. */
    public static function roleLabels(): array
    {
        return [
            self::ROLE_OWNER => 'Store owner',
            self::ROLE_MANAGER => 'Manager',
            self::ROLE_SALES => 'Sales / chat staff',
            self::ROLE_CASHIER => 'Cashier',
            self::ROLE_UPLOADER => 'Uploader / storekeeper',
            self::ROLE_DISPATCHER => 'Dispatcher',
        ];
    }

    /**
     * Capability array in the shape add_role() expects.
     *
     * @return array<string,bool>
     */
    public static function roleCapabilities(string $role): array
    {
        $capabilities = ['read' => true];

        foreach (self::roles()[$role] ?? [] as $capability) {
            $capabilities[self::wp($capability)] = true;
        }

        return $capabilities;
    }
}
