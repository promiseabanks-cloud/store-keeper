<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Cli;

use StoreKeeper\Commerce\Orders\OrderService;

/**
 * `wp storekeeper orders expire-holds` (§9.5, §22.4).
 *
 * The same work runs hourly from WP-Cron; the command exists so an operator can
 * run it deliberately after a long outage.
 */
final class OrderCommand
{
    public function __construct(private readonly OrderService $orders)
    {
    }

    /**
     * Cancel open orders whose payment hold has expired and release their stock.
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Maximum orders to expire in one run. Default 100.
     *
     * ## EXAMPLES
     *
     *     wp storekeeper orders expire-holds
     *
     * @param list<string> $args
     * @param array<string,mixed> $assocArgs
     */
    public function expireHolds(array $args, array $assocArgs): void
    {
        $limit = isset($assocArgs['limit']) ? max(1, (int) $assocArgs['limit']) : 100;
        $result = $this->orders->expireHolds($limit);

        foreach ($result['failed'] as $failure) {
            \WP_CLI::warning($failure);
        }

        \WP_CLI::success(sprintf('%d order hold(s) expired.', count($result['expired'])));
    }
}
