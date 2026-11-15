<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core\Exception;

/**
 * A payment gateway refused a call or could not be reached (§13.2).
 *
 * Distinguished from ValidationFailed so staff see "the gateway is unhappy",
 * not "your input is wrong".
 */
final class GatewayError extends StoreKeeperException
{
    /** @param array<string,mixed> $context */
    public function __construct(string $message, private readonly array $context = [])
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'gateway_error';
    }

    public function httpStatus(): int
    {
        return 502;
    }

    /** @return array<string,mixed> */
    public function context(): array
    {
        return $this->context;
    }
}
