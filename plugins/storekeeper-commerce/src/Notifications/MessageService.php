<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Notifications;

use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Audit\AuditLogger;
use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;

/**
 * Templates, the outbox and delivery attempts (§12).
 *
 * `queue()` is called inside the request that caused the message (an order, a
 * sign-in). `dispatch()` runs later, from cron or `wp storekeeper messages
 * dispatch`, and is the only place a provider is called. A failed send stays
 * queued for another attempt and becomes `failed` after five, so one dead
 * channel cannot lose an order confirmation silently.
 */
final class MessageService
{
    public const MAX_ATTEMPTS = 5;

    /** @param array<string,Channel> $channels keyed by template channel */
    public function __construct(
        private readonly MessageTemplateRepository $templates,
        private readonly MessageLogRepository $logs,
        private readonly array $channels,
        private readonly Clock $clock,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * Render and enqueue a message.
     *
     * @param array<string,string|int> $variables values for `{{name}}` placeholders
     * @param array{order_id?:int,customer_id?:int} $context links back to the record
     * @return int|null null when the event has no template or the recipient is unusable
     */
    public function queue(string $event, string $recipient, array $variables = [], array $context = []): ?int
    {
        $recipient = trim($recipient);

        if ($recipient === '') {
            return null;
        }

        $template = $this->template($event);

        if ($template === null || (isset($template['is_active']) && !$template['is_active'])) {
            return null;
        }

        $body = $this->render((string) $template['body'], $variables);
        $subject = $this->render((string) ($template['subject'] ?? $event), $variables);
        $now = $this->clock->nowString();

        return $this->logs->create([
            'event' => $event,
            'channel' => (string) $template['channel'],
            'category' => (string) ($template['category'] ?? 'utility'),
            'recipient' => mb_substr($recipient, 0, 191),
            'subject' => $subject,
            'body' => $body,
            'order_id' => isset($context['order_id']) ? (int) $context['order_id'] : null,
            'customer_id' => isset($context['customer_id']) ? (int) $context['customer_id'] : null,
            'status' => 'queued',
            'attempts' => 0,
            'provider' => null,
            'provider_message_id' => null,
            'cost_micro_usd' => null,
            'cost_kobo' => null,
            'error' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Deliver queued messages.
     *
     * @return array{sent:int,failed:int,remaining:int}
     */
    public function dispatch(int $limit = 50): array
    {
        $sent = 0;
        $failed = 0;

        foreach ($this->logs->queued($limit) as $message) {
            $channel = $this->channels[(string) $message['channel']] ?? null;
            $attempts = (int) $message['attempts'] + 1;

            if ($channel === null) {
                $this->logs->markAttemptFailed(
                    (int) $message['id'],
                    sprintf('No channel configured for "%s".', (string) $message['channel']),
                    $this->clock->nowString(),
                    true
                );
                $failed++;

                continue;
            }

            try {
                $providerId = $channel->send(
                    (string) $message['recipient'],
                    (string) ($message['subject'] ?? ''),
                    (string) ($message['body'] ?? '')
                );
                $this->logs->markSent((int) $message['id'], $providerId, $this->clock->nowString());
                $sent++;
            } catch (\Throwable $error) {
                $this->logs->markAttemptFailed(
                    (int) $message['id'],
                    $error->getMessage(),
                    $this->clock->nowString(),
                    $attempts >= self::MAX_ATTEMPTS
                );
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'remaining' => $this->logs->countQueued()];
    }

    public function pending(): int
    {
        return $this->logs->countQueued();
    }

    /**
     * The outbox as the Messages screen reads it (§12.4).
     *
     * @return array{rows:list<array<string,mixed>>,counts:array<string,int>}
     */
    public function recent(?string $status, int $limit = 100): array
    {
        if ($status !== null && $status !== '' && !in_array($status, ['queued', 'sent', 'failed'], true)) {
            throw new ValidationFailed('Unknown message status.', ['status' => 'queued, sent or failed.']);
        }

        $rows = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'event' => (string) $row['event'],
            'channel' => (string) $row['channel'],
            'category' => (string) $row['category'],
            'recipient' => (string) $row['recipient'],
            'subject' => $row['subject'] === null ? null : (string) $row['subject'],
            'body' => $row['body'] === null ? null : (string) $row['body'],
            'order_id' => $row['order_id'] === null ? null : (int) $row['order_id'],
            'customer_id' => $row['customer_id'] === null ? null : (int) $row['customer_id'],
            'status' => (string) $row['status'],
            'attempts' => (int) $row['attempts'],
            'provider_message_id' => $row['provider_message_id'] === null ? null : (string) $row['provider_message_id'],
            'error' => $row['error'] === null ? null : (string) $row['error'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ], $this->logs->recent($status, min(200, max(1, $limit))));

        return ['rows' => $rows, 'counts' => $this->logs->counts()];
    }

    /**
     * Every event with the code default and the store's override, if any.
     * The screen edits the rendered copy, so an inactive override is shown too.
     *
     * @return list<array<string,mixed>>
     */
    public function templates(): array
    {
        $rows = [];

        foreach (Templates::events() as $event) {
            $default = Templates::default($event);

            if ($default === null) {
                continue;
            }

            $channel = (string) $default['channel'];
            $override = $this->templates->findAny($event, $channel);
            $active = $override !== null && (int) $override['is_active'] === 1;

            $rows[] = [
                'event' => $event,
                'channel' => $channel,
                'category' => (string) $default['category'],
                'subject' => $active && $override['subject'] !== null
                    ? (string) $override['subject']
                    : (string) $default['subject'],
                'body' => $active ? (string) $override['body'] : (string) $default['body'],
                'default_subject' => (string) $default['subject'],
                'default_body' => (string) $default['body'],
                'is_overridden' => $active,
                'is_active' => $active,
                'placeholders' => self::placeholders((string) $default['subject'] . ' ' . (string) $default['body']),
            ];
        }

        return $rows;
    }

    /**
     * Save a store override for one event, or `reset: true` to fall back to the
     * code default. Editing the copy never touches already queued messages: they
     * store their rendered subject and body (D-032).
     *
     * @param array<string,mixed> $input subject, body, is_active, reset
     * @return array<string,mixed> the event's row in {@see templates()}
     */
    public function saveTemplate(string $event, array $input, Actor $actor): array
    {
        $default = Templates::default($event);

        if ($default === null) {
            throw new ValidationFailed('Unknown message event.', ['event' => implode(', ', Templates::events())]);
        }

        $channel = (string) $default['channel'];
        $existing = $this->templates->findAny($event, $channel);
        $before = $existing === null ? null : [
            'subject' => $existing['subject'] === null ? null : (string) $existing['subject'],
            'body' => (string) $existing['body'],
            'is_active' => (int) $existing['is_active'] === 1,
        ];

        if (!empty($input['reset'])) {
            $this->templates->forget($event, $channel);

            $this->audit?->log($actor, 'message.template_reset', 'message_template', $existing === null ? null : (int) $existing['id'], $before, null);

            return $this->templateRow($event);
        }

        $subject = trim((string) ($input['subject'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));

        if ($subject === '') {
            throw new ValidationFailed('A message needs a subject.', ['subject' => 'Required.']);
        }

        if ($body === '') {
            throw new ValidationFailed('A message needs a body.', ['body' => 'Required.']);
        }

        $isActive = array_key_exists('is_active', $input) ? (bool) $input['is_active'] : true;

        $id = $this->templates->upsert([
            'event' => $event,
            'channel' => $channel,
            'language' => 'en',
            'subject' => mb_substr($subject, 0, 255),
            'body' => $body,
            'is_active' => $isActive ? 1 : 0,
        ]);

        $this->audit?->log($actor, 'message.template_saved', 'message_template', $id, $before, [
            'subject' => mb_substr($subject, 0, 255),
            'body' => $body,
            'is_active' => $isActive,
        ]);

        return $this->templateRow($event);
    }

    /** @return array<string,mixed> */
    private function templateRow(string $event): array
    {
        foreach ($this->templates() as $row) {
            if ($row['event'] === $event) {
                return $row;
            }
        }

        return [];
    }

    /** @return list<string> */
    private static function placeholders(string $text): array
    {
        preg_match_all('/\{\{([a-z0-9_]+)\}\}/i', $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @return array<string,mixed>|null */
    private function template(string $event): ?array
    {
        $default = Templates::default($event);

        if ($default === null) {
            return null;
        }

        $override = $this->templates->find($event, (string) $default['channel']);

        return $override ?? $default;
    }

    /** @param array<string,string|int> $variables */
    private function render(string $text, array $variables): string
    {
        $replacements = [];

        foreach ($variables as $name => $value) {
            $replacements['{{' . $name . '}}'] = (string) $value;
        }

        $rendered = strtr($text, $replacements);

        // A variable the caller did not supply must not leak "{{name}}" to a
        // customer; the message still sends, with that sentence's blank.
        return trim((string) preg_replace('/\{\{[a-z0-9_]+\}\}/i', '', $rendered));
    }
}
