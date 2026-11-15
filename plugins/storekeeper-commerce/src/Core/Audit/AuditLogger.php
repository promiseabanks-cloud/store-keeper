<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core\Audit;

/**
 * Records sensitive actions (§5.2, §19). Services always log through this.
 */
interface AuditLogger
{
    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public function log(
        Actor $actor,
        string $action,
        string $entityType,
        int|string|null $entityId = null,
        ?array $before = null,
        ?array $after = null,
    ): void;
}
