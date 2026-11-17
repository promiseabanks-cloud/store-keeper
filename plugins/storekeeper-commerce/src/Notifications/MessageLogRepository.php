<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Notifications;

use StoreKeeper\Commerce\Core\Database\Connection;

/**
 * `{p}message_log` is both the audit trail and the outbox: a message is written
 * as `queued` inside the request that caused it and delivered by a later
 * dispatch run, so a slow or failing provider never breaks an order (§12.4).
 */
final class MessageLogRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert($this->db->prefix() . 'message_log', $data);
    }

    /** @return list<array<string,mixed>> queued messages, oldest first */
    public function queued(int $limit): array
    {
        return $this->db->select(
            sprintf(
                "SELECT * FROM %smessage_log WHERE status = 'queued' ORDER BY id ASC LIMIT ?",
                $this->db->prefix()
            ),
            [max(1, $limit)]
        );
    }

    public function markSent(int $messageId, ?string $providerMessageId, string $at): void
    {
        $this->db->execute(
            sprintf(
                "UPDATE %smessage_log SET attempts = attempts + 1, status = 'sent', provider_message_id = ?, error = NULL, updated_at = ? WHERE id = ?",
                $this->db->prefix()
            ),
            [$providerMessageId, $at, $messageId]
        );
    }

    public function markAttemptFailed(int $messageId, string $error, string $at, bool $giveUp): void
    {
        $this->db->execute(
            sprintf(
                'UPDATE %smessage_log SET attempts = attempts + 1, status = ?, error = ?, updated_at = ? WHERE id = ?',
                $this->db->prefix()
            ),
            [$giveUp ? 'failed' : 'queued', mb_substr($error, 0, 255), $at, $messageId]
        );
    }

    public function countQueued(): int
    {
        return (int) $this->db->scalar(
            sprintf("SELECT COUNT(*) FROM %smessage_log WHERE status = 'queued'", $this->db->prefix())
        );
    }
}
