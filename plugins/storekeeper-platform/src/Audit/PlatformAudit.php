<?php

declare(strict_types=1);

namespace StoreKeeper\Platform\Audit;

use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;

/**
 * The Owner's audit trail (§17.5). Every console action and every impersonation
 * writes here; impersonation also writes the merchant's own audit log.
 */
final class PlatformAudit
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /** @param array<string,mixed>|null $details */
    public function log(int $actorId, string $action, ?int $tenantId = null, ?int $entityId = null, ?array $before = null, ?array $after = null): void
    {
        $this->db->insert($this->db->networkPrefix() . 'platform_audit_log', [
            'actor_id' => $actorId,
            'tenant_id' => $tenantId,
            'action' => $action,
            'details' => json_encode([
                'entity_id' => $entityId,
                'before' => $before,
                'after' => $after,
            ], JSON_THROW_ON_ERROR),
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null,
            'created_at' => $this->clock->nowString(),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 50): array
    {
        return $this->db->select(
            sprintf('SELECT * FROM %splatform_audit_log ORDER BY id DESC LIMIT ?', $this->db->networkPrefix()),
            [$limit]
        );
    }
}
