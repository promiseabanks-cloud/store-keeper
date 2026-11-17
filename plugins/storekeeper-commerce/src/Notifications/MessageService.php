<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Notifications;

use StoreKeeper\Commerce\Core\Clock;

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
