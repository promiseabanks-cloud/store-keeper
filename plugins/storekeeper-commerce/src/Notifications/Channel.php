<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Notifications;

/**
 * One delivery channel (§12). WhatsApp, SMS and email adapters implement this;
 * the service owns templates, the outbox and retries, never the provider call.
 */
interface Channel
{
    /**
     * Deliver one rendered message.
     *
     * @return string|null the provider's message id, when it returns one
     *
     * @throws ChannelFailed when the provider refuses or is unreachable
     */
    public function send(string $recipient, string $subject, string $body): ?string;
}
