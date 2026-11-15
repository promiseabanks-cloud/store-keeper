<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Cli;

use StoreKeeper\Commerce\Inventory\InventoryService;

/**
 * `wp storekeeper stock verify [--fix] [--variant=<id>] [--location=<id>]` (§9.6).
 */
final class StockCommand
{
    public function __construct(private readonly InventoryService $inventory)
    {
    }

    /**
     * Recompute stock levels from the ledger and report any mismatch.
     *
     * ## OPTIONS
     *
     * [--fix]
     * : Rewrite stock_levels from the ledger. The ledger is never modified.
     *
     * [--variant=<id>]
     * : Limit the check to one variant.
     *
     * [--location=<id>]
     * : Limit the check to one location.
     *
     * ## EXAMPLES
     *
     *     wp storekeeper stock verify
     *     wp storekeeper stock verify --fix
     *
     * @param list<string> $args
     * @param array<string,mixed> $assocArgs
     */
    public function verify(array $args, array $assocArgs): void
    {
        $variantId = isset($assocArgs['variant']) ? (int) $assocArgs['variant'] : null;
        $locationId = isset($assocArgs['location']) ? (int) $assocArgs['location'] : null;
        $fix = !empty($assocArgs['fix']);

        $mismatches = $this->inventory->verify($variantId, $locationId, $fix);

        if ($mismatches === []) {
            \WP_CLI::success('Ledger clean: every stock level equals the sum of its movements.');

            return;
        }

        $rows = array_map(static fn (array $mismatch): array => [
            'variant_id' => $mismatch['variant_id'],
            'location_id' => $mismatch['location_id'],
            'field' => $mismatch['type'],
            'ledger' => $mismatch['expected'],
            'stored' => $mismatch['actual'],
        ], $mismatches);

        \WP_CLI\Utils\format_items('table', $rows, ['variant_id', 'location_id', 'field', 'ledger', 'stored']);

        if ($fix) {
            \WP_CLI::success(sprintf('%d mismatched level(s) rewritten from the ledger.', count($mismatches)));

            return;
        }

        \WP_CLI::error(sprintf(
            '%d mismatch(es) found. Re-run with --fix to rewrite the cache from the ledger.',
            count($mismatches)
        ));
    }

    /**
     * Print the ledger for one variant, newest first.
     *
     * ## OPTIONS
     *
     * <variant-id>
     * : The variant to inspect.
     *
     * [--location=<id>]
     * : Limit to one location.
     *
     * @param list<string> $args
     * @param array<string,mixed> $assocArgs
     */
    public function movements(array $args, array $assocArgs): void
    {
        $variantId = (int) ($args[0] ?? 0);
        $locationId = isset($assocArgs['location']) ? (int) $assocArgs['location'] : null;

        $rows = array_map(static fn (array $movement): array => [
            'id' => $movement['id'],
            'type' => $movement['type'],
            'location_id' => $movement['location_id'],
            'on_hand' => $movement['on_hand_delta'],
            'reserved' => $movement['reserved_delta'],
            'order_id' => $movement['order_id'],
            'created_at' => $movement['created_at'],
        ], $this->inventory->movements($variantId, $locationId, 200));

        \WP_CLI\Utils\format_items('table', $rows, ['id', 'type', 'location_id', 'on_hand', 'reserved', 'order_id', 'created_at']);
    }
}
