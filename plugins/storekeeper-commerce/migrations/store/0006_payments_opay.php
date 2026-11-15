<?php

declare(strict_types=1);

/**
 * D-014: the third gateway is OPay, confirmed by the Owner on 2026-11-15.
 *
 * `opay` is added alongside the brief's placeholder `gateway3` so that no
 * existing payment row can break. The adapter itself is Phase 3 work (§13.2).
 */
return static function (string $prefix): array {
    return [
        "ALTER TABLE {p}payments MODIFY COLUMN method ENUM(
            'bank_transfer','virtual_account','paystack','flutterwave','opay','gateway3',
            'cash','pos_terminal','pay_on_delivery','other'
        ) NOT NULL",
    ];
};
