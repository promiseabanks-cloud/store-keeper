<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Notifications;

/**
 * Message templates (§12.2), with sensible defaults in code.
 *
 * A store may override any event/channel pair by saving a row in
 * `{p}message_templates`; without an override the default below is used, so a
 * fresh store can send a correct order confirmation with no setup.
 */
final class Templates
{
    /**
     * @return array{event:string,channel:string,category:string,subject:string,body:string}
     */
    public static function default(string $event): ?array
    {
        $defaults = [
            'order_created_buyer' => [
                'channel' => 'email',
                'category' => 'utility',
                'subject' => 'Order {{order_number}} received — {{store_name}}',
                'body' => "Hi {{customer_name}},\n\n"
                    . "We have your order {{order_number}}: {{items_count}} item(s), total {{order_total}}.\n\n"
                    . "Pay by bank transfer:\n{{bank_details}}\n\n"
                    . "Use {{order_number}} as the reference. Your items are held until the payment arrives.\n\n"
                    . "View your order: {{order_url}}\n\n"
                    . "{{store_name}}",
            ],
            'order_created_staff' => [
                'channel' => 'email',
                'category' => 'service',
                'subject' => 'New order {{order_number}} — {{order_total}}',
                'body' => "New web order {{order_number}} from {{customer_name}} ({{customer_phone}}).\n\n"
                    . "{{items_count}} item(s), total {{order_total}}.\n\n"
                    . "{{order_url}}\n\n"
                    . "{{store_name}}",
            ],
            'otp_login' => [
                'channel' => 'email',
                'category' => 'authentication',
                'subject' => 'Your {{store_name}} sign-in code',
                'body' => 'Your sign-in code is {{code}}. It expires in {{minutes}} minutes.',
            ],
        ];

        if (!isset($defaults[$event])) {
            return null;
        }

        return ['event' => $event, ...$defaults[$event]];
    }

    /** @return list<string> */
    public static function events(): array
    {
        return ['order_created_buyer', 'order_created_staff', 'otp_login'];
    }
}
