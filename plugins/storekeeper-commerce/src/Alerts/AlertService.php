<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Alerts;

use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;

/**
 * Merchant-facing alerts (§8.5, §9.6, §9.9, §9.10). Deduplicates so a single
 * long-running problem does not create hundreds of rows.
 */
final class AlertService
{
    public const OVERSELL = 'oversell';
    public const LOW_STOCK = 'low_stock';
    public const LEDGER_MISMATCH = 'ledger_mismatch';
    public const DEVICE_STALE = 'device_stale';
    public const PAYMENT_MISMATCH = 'payment_mismatch';
    public const WEBHOOK_FAILURE = 'webhook_failure';
    public const IMPORT_ERRORS = 'import_errors';

    public const INFO = 'info';
    public const WARNING = 'warning';
    public const CRITICAL = 'critical';

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     * @return int|null the alert id, or null when an identical open alert already exists
     */
    public function raise(
        string $type,
        string $severity,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $payload = [],
        bool $dedupe = true,
    ): ?int {
        $table = $this->db->prefix() . 'alerts';

        if ($dedupe) {
            $existing = $this->db->first(
                sprintf(
                    'SELECT id FROM %s WHERE type = ? AND status = ? AND subject_type <=> ? AND subject_id <=> ? LIMIT 1',
                    $table
                ),
                [$type, 'open', $subjectType, $subjectId]
            );

            if ($existing !== null) {
                return null;
            }
        }

        return $this->db->insert($table, [
            'type' => $type,
            'severity' => $severity,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => 'open',
            'created_at' => $this->clock->nowString(),
        ]);
    }

    public function resolve(int $alertId, ?int $resolvedBy, string $resolution): void
    {
        $this->db->update(
            $this->db->prefix() . 'alerts',
            [
                'status' => 'resolved',
                'resolved_by' => $resolvedBy,
                'resolution' => $resolution,
                'resolved_at' => $this->clock->nowString(),
            ],
            ['id' => $alertId]
        );
    }

    public function openCount(?string $type = null): int
    {
        $sql = sprintf('SELECT COUNT(*) FROM %salerts WHERE status = ?', $this->db->prefix());
        $params = ['open'];

        if ($type !== null) {
            $sql .= ' AND type = ?';
            $params[] = $type;
        }

        return (int) $this->db->scalar($sql, $params);
    }

    /** @return list<array<string,mixed>> */
    public function open(?string $type = null, int $limit = 50): array
    {
        $sql = sprintf('SELECT * FROM %salerts WHERE status = ?', $this->db->prefix());
        $params = ['open'];

        if ($type !== null) {
            $sql .= ' AND type = ?';
            $params[] = $type;
        }

        $sql .= ' ORDER BY id DESC LIMIT ?';
        $params[] = $limit;

        return $this->db->select($sql, $params);
    }
}
