<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Orders\Exception;

use StoreKeeper\Commerce\Core\Exception\StoreKeeperException;

/**
 * An order state change that the state machine forbids (§12.1).
 */
final class InvalidTransition extends StoreKeeperException
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $subject = 'order',
    ) {
        parent::__construct(sprintf('Cannot move %s from "%s" to "%s".', $subject, $from, $to));
    }

    public function errorCode(): string
    {
        return 'invalid_transition';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function context(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'subject' => $this->subject];
    }
}
