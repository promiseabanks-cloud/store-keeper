<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Notifications;

/**
 * Development transport: writes the rendered message to the PHP error log and
 * counts it as delivered. Selected with the `notification_transport` option set
 * to `log`, for a preview install with no mail server. Never use it in
 * production: no customer receives anything.
 */
final class LogChannel implements Channel
{
    public function send(string $recipient, string $subject, string $body): ?string
    {
        error_log(sprintf("[Store Keeper] message to %s\nSubject: %s\n%s", $recipient, $subject, $body));

        return 'log';
    }
}
