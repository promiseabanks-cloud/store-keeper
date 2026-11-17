<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Notifications;

/**
 * Email through WordPress (`wp_mail`). No provider is needed, so order
 * confirmations work from day one; a merchant can move to transactional email
 * later by filtering `wp_mail` or replacing this channel.
 */
final class EmailChannel implements Channel
{
    public function send(string $recipient, string $subject, string $body): ?string
    {
        if (!function_exists('wp_mail')) {
            throw new ChannelFailed('Email is not available outside WordPress.');
        }

        $sent = wp_mail($recipient, $subject, $body);

        if ($sent !== true) {
            throw new ChannelFailed('wp_mail() could not hand the message to the mail transport.');
        }

        return null;
    }
}
