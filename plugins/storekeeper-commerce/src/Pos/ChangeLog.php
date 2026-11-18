<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Pos;

use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;

/**
 * The monotonically increasing change feed `/sync/pull` reads (§15.3).
 *
 * Rows are appended by the hooks the store already fires, so no service needs
 * to know about POS sync. The cursor is the newest row id; a device that has
 * been away longer than the retention window is told to resync in full.
 */
final class ChangeLog
{
    /** How long an incremental cursor stays valid (§15.3). */
    public const RETENTION_DAYS = 7;

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /** @param array<string,mixed> $payload */
    public function record(string $entityType, ?int $entityId, string $action, array $payload = []): int
    {
        return $this->db->insert($this->db->prefix() . 'change_log', [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'payload' => $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => $this->clock->nowString(),
        ]);
    }

    public function latestId(): int
    {
        return (int) $this->db->scalar(
            sprintf('SELECT COALESCE(MAX(id), 0) FROM %schange_log', $this->db->prefix())
        );
    }

    public function oldestId(): int
    {
        return (int) $this->db->scalar(
            sprintf('SELECT COALESCE(MIN(id), 0) FROM %schange_log', $this->db->prefix())
        );
    }

    /** @return list<array<string,mixed>> */
    public function since(int $cursor, int $limit = 500): array
    {
        return $this->db->select(
            sprintf(
                'SELECT * FROM %schange_log WHERE id > ? ORDER BY id ASC LIMIT ?',
                $this->db->prefix()
            ),
            [$cursor, max(1, $limit)]
        );
    }

    /**
     * True when the device's cursor is older than the retention window, so the
     * next pull has to send a full snapshot.
     */
    public function tooOld(int $cursor): bool
    {
        if ($cursor <= 0) {
            return true;
        }

        $oldest = $this->db->first(
            sprintf('SELECT id, created_at FROM %schange_log ORDER BY id ASC LIMIT 1', $this->db->prefix())
        );

        if ($oldest === null) {
            return false;
        }

        $cutoff = gmdate('Y-m-d H:i:s.v', strtotime('-' . self::RETENTION_DAYS . ' days'));

        return (int) $oldest['id'] > $cursor && (string) $oldest['created_at'] < $cutoff;
    }
}
