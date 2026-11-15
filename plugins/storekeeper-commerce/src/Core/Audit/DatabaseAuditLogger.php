<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core\Audit;

use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;

/**
 * Writes to {p}audit_log, including the before/after snapshots that make
 * price and stock changes defensible (§5.2, §10.5).
 */
final class DatabaseAuditLogger implements AuditLogger
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    public function log(
        Actor $actor,
        string $action,
        string $entityType,
        int|string|null $entityId = null,
        ?array $before = null,
        ?array $after = null,
    ): void {
        $this->db->insert($this->db->prefix() . 'audit_log', [
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => is_string($entityId) ? null : $entityId,
            'before_data' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_data' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'ip' => $actor->ip,
            'created_at' => $this->clock->nowString(),
        ]);
    }
}
