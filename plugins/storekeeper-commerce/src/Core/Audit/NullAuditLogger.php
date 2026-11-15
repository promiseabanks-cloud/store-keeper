<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core\Audit;

/**
 * Discards audit writes; used by pure unit tests that do not run a database.
 */
final class NullAuditLogger implements AuditLogger
{
    public function log(
        Actor $actor,
        string $action,
        string $entityType,
        int|string|null $entityId = null,
        ?array $before = null,
        ?array $after = null,
    ): void {
    }
}
