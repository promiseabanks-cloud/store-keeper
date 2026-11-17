<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Cli;

use StoreKeeper\Commerce\Notifications\MessageService;

/**
 * `wp storekeeper messages dispatch` (§12.4).
 *
 * The same work runs hourly from WP-Cron/system cron; the command exists so an
 * operator can drain the outbox after an outage or a provider fix.
 */
final class MessageCommand
{
    public function __construct(private readonly MessageService $messages)
    {
    }

    /**
     * Deliver queued messages.
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Maximum messages to attempt in one run. Default 100.
     *
     * ## EXAMPLES
     *
     *     wp storekeeper messages dispatch
     *
     * @param list<string> $args
     * @param array<string,mixed> $assocArgs
     */
    public function dispatch(array $args, array $assocArgs): void
    {
        $limit = isset($assocArgs['limit']) ? max(1, (int) $assocArgs['limit']) : 100;
        $result = $this->messages->dispatch($limit);

        \WP_CLI::success(sprintf(
            '%d sent, %d failed, %d still queued.',
            $result['sent'],
            $result['failed'],
            $result['remaining']
        ));
    }
}
