<?php

declare(strict_types=1);

/**
 * §8.4 Orders, payments and fulfilment: orders with three independent
 * statuses, snapshot order lines, payments, refunds, shipping zones/methods,
 * shipments and their timeline.
 */
return static function (string $prefix): array {
    return [
        "CREATE TABLE IF NOT EXISTS {p}orders (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            number VARCHAR(32) NOT NULL UNIQUE,
            channel ENUM('web','whatsapp','instagram','phone','counter','other') NOT NULL,
            status ENUM('draft','open','completed','cancelled') NOT NULL DEFAULT 'open',
            payment_status ENUM('unpaid','awaiting_confirmation','partially_paid','paid','partially_refunded','refunded','pay_on_delivery') NOT NULL DEFAULT 'unpaid',
            fulfilment_status ENUM('unfulfilled','partially_fulfilled','fulfilled','returned') NOT NULL DEFAULT 'unfulfilled',
            customer_id BIGINT UNSIGNED NULL,
            customer_group_id BIGINT UNSIGNED NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'NGN',
            subtotal_kobo BIGINT NOT NULL,
            discount_kobo BIGINT NOT NULL DEFAULT 0,
            shipping_kobo BIGINT NOT NULL DEFAULT 0,
            fee_kobo BIGINT NOT NULL DEFAULT 0,
            total_kobo BIGINT NOT NULL,
            paid_kobo BIGINT NOT NULL DEFAULT 0,
            refunded_kobo BIGINT NOT NULL DEFAULT 0,
            shipping_method_id BIGINT UNSIGNED NULL,
            shipping_address JSON NULL,
            hold_expires_at DATETIME(3) NULL,
            public_token CHAR(32) NOT NULL UNIQUE,
            idempotency_key VARCHAR(64) NOT NULL UNIQUE,
            created_by_type ENUM('staff','customer','device','system') NOT NULL,
            created_by_id BIGINT UNSIGNED NULL,
            device_id BIGINT UNSIGNED NULL,
            client_created_at DATETIME(3) NULL,
            customer_note TEXT NULL,
            staff_note TEXT NULL,
            cancel_reason VARCHAR(255) NULL,
            created_at DATETIME(3) NOT NULL,
            updated_at DATETIME(3) NOT NULL,
            cancelled_at DATETIME(3) NULL,
            completed_at DATETIME(3) NULL,
            KEY status (status, payment_status, fulfilment_status),
            KEY customer (customer_id),
            KEY channel_created (channel, created_at),
            KEY hold (hold_expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}order_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            variant_id BIGINT UNSIGNED NOT NULL,
            product_name VARCHAR(255) NOT NULL,
            variant_title VARCHAR(255) NOT NULL,
            sku VARCHAR(64) NOT NULL,
            image_attachment_id BIGINT UNSIGNED NULL,
            pack_unit_id BIGINT UNSIGNED NULL,
            pack_name VARCHAR(50) NOT NULL DEFAULT 'piece',
            pack_pieces INT UNSIGNED NOT NULL DEFAULT 1,
            qty INT UNSIGNED NOT NULL,
            qty_pieces INT UNSIGNED NOT NULL,
            qty_fulfilled_pieces INT UNSIGNED NOT NULL DEFAULT 0,
            unit_price_kobo BIGINT NOT NULL,
            list_price_kobo BIGINT NOT NULL,
            price_source ENUM('customer','group','group_tier','base','base_tier','manual') NOT NULL,
            discount_kobo BIGINT NOT NULL DEFAULT 0,
            line_total_kobo BIGINT NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            KEY order_ref (order_id),
            KEY variant (variant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}payments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            order_id BIGINT UNSIGNED NOT NULL,
            method ENUM('bank_transfer','virtual_account','paystack','flutterwave','gateway3','cash','pos_terminal','pay_on_delivery','other') NOT NULL,
            status ENUM('pending','awaiting_confirmation','succeeded','failed','cancelled') NOT NULL,
            amount_kobo BIGINT NOT NULL,
            gateway_reference VARCHAR(100) NULL,
            gateway_fee_kobo BIGINT NULL,
            gateway_payload JSON NULL,
            proof_attachment_id BIGINT UNSIGNED NULL,
            sender_name VARCHAR(191) NULL,
            confirmed_by BIGINT UNSIGNED NULL,
            confirmed_at DATETIME(3) NULL,
            idempotency_key VARCHAR(64) NOT NULL UNIQUE,
            created_at DATETIME(3) NOT NULL,
            updated_at DATETIME(3) NOT NULL,
            UNIQUE KEY gw_ref (method, gateway_reference),
            KEY order_ref (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}refunds (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            order_id BIGINT UNSIGNED NOT NULL,
            payment_id BIGINT UNSIGNED NULL,
            amount_kobo BIGINT NOT NULL,
            method VARCHAR(32) NOT NULL,
            reason VARCHAR(255) NOT NULL,
            restock TINYINT(1) NOT NULL DEFAULT 0,
            gateway_reference VARCHAR(100) NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(3) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}shipping_zones (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}shipping_zone_areas (
            zone_id BIGINT UNSIGNED NOT NULL,
            state VARCHAR(50) NOT NULL,
            lga VARCHAR(100) NULL,
            KEY zone (zone_id),
            KEY area (state, lga)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}shipping_methods (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            zone_id BIGINT UNSIGNED NULL,
            name VARCHAR(100) NOT NULL,
            type ENUM('flat','weight_band','free_over','pickup','quote_later') NOT NULL,
            config JSON NOT NULL,
            group_ids JSON NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}shipments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            order_id BIGINT UNSIGNED NOT NULL,
            status ENUM('pending','packed','ready_for_pickup','dispatched','delivered','failed_delivery','returned','cancelled') NOT NULL DEFAULT 'pending',
            method_name VARCHAR(100) NULL,
            carrier VARCHAR(100) NULL,
            waybill_number VARCHAR(100) NULL,
            rider_name VARCHAR(100) NULL,
            rider_phone VARCHAR(20) NULL,
            photo_attachment_id BIGINT UNSIGNED NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            packed_at DATETIME(3) NULL,
            dispatched_at DATETIME(3) NULL,
            delivered_at DATETIME(3) NULL,
            created_at DATETIME(3) NOT NULL,
            updated_at DATETIME(3) NOT NULL,
            KEY order_ref (order_id),
            KEY status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}shipment_items (
            shipment_id BIGINT UNSIGNED NOT NULL,
            order_item_id BIGINT UNSIGNED NOT NULL,
            qty_pieces INT UNSIGNED NOT NULL,
            PRIMARY KEY (shipment_id, order_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS {p}shipment_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            shipment_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(32) NOT NULL,
            note VARCHAR(255) NULL,
            actor_type ENUM('staff','system','customer') NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            notified TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME(3) NOT NULL,
            KEY shipment (shipment_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
};
