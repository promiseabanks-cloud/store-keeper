<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Notifications;

use StoreKeeper\Commerce\Core\Database\Connection;

/** Store-specific template overrides (§8.5 `message_templates`). */
final class MessageTemplateRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string,mixed>|null an active override for this event and channel */
    public function find(string $event, string $channel): ?array
    {
        return $this->db->first(
            sprintf(
                'SELECT * FROM %smessage_templates WHERE event = ? AND channel = ? AND is_active = 1 LIMIT 1',
                $this->db->prefix()
            ),
            [$event, $channel]
        );
    }

    /** @return array<string,mixed>|null an override whatever its active state */
    public function findAny(string $event, string $channel): ?array
    {
        return $this->db->first(
            sprintf(
                'SELECT * FROM %smessage_templates WHERE event = ? AND channel = ? ORDER BY id DESC LIMIT 1',
                $this->db->prefix()
            ),
            [$event, $channel]
        );
    }

    /** @return list<array<string,mixed>> every stored override */
    public function all(): array
    {
        return $this->db->select(
            sprintf('SELECT * FROM %smessage_templates ORDER BY event ASC, channel ASC', $this->db->prefix())
        );
    }

    /** Remove the store's override so the code default applies again. */
    public function forget(string $event, string $channel): void
    {
        $this->db->delete($this->db->prefix() . 'message_templates', [
            'event' => $event,
            'channel' => $channel,
        ]);
    }

    /** @param array<string,mixed> $data */
    public function upsert(array $data): int
    {
        $table = $this->db->prefix() . 'message_templates';
        $existing = $this->db->first(
            sprintf('SELECT id FROM %s WHERE event = ? AND channel = ? AND language = ?', $table),
            [$data['event'], $data['channel'], $data['language'] ?? 'en']
        );

        if ($existing !== null) {
            $this->db->update($table, $data, ['id' => $existing['id']]);

            return (int) $existing['id'];
        }

        return $this->db->insert($table, $data);
    }
}
