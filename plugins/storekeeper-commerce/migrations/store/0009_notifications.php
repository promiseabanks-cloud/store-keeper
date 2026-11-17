<?php

declare(strict_types=1);

/**
 * §8.5 notifications: templates get a subject (email needs one), and a queued
 * message keeps its rendered subject and body so the outbox row is sendable on
 * its own and stays readable after a template changes. The log also records how
 * many delivery attempts it has had, so a failing channel retries instead of
 * losing the message.
 */
return static function (string $prefix): array {
    return [
        "ALTER TABLE {p}message_templates ADD COLUMN subject VARCHAR(255) NULL AFTER event",
        "ALTER TABLE {p}message_log ADD COLUMN subject VARCHAR(255) NULL AFTER recipient",
        "ALTER TABLE {p}message_log ADD COLUMN body TEXT NULL AFTER subject",
        "ALTER TABLE {p}message_log ADD COLUMN attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER status",
        "ALTER TABLE {p}message_log ADD KEY status_created (status, created_at)",
    ];
};
