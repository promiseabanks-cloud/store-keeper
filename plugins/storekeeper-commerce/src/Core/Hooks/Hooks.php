<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core\Hooks;

/**
 * Extension points (§6.1 principle 5, §12.6). Always called after the database
 * transaction commits, never inside it.
 */
interface Hooks
{
    /** @param array<string,mixed> $payload */
    public function do(string $name, array $payload = []): void;
}
