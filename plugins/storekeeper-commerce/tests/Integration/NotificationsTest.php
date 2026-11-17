<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Tests\Support\TestStore;

/**
 * Notifications (§12): queue in-request, deliver later, retry a failing channel
 * and never lose the message. No provider is called from an order.
 */
final class NotificationsTest extends TestCase
{
    private TestStore $store;

    protected function setUp(): void
    {
        $this->store = TestStore::create();
    }

    public function testQueueingRendersTheTemplateAndStoresTheRenderedMessage(): void
    {
        $id = $this->store->messages->queue('order_created_buyer', 'ada@example.com', [
            'order_number' => 'SK-00007',
            'order_total' => '₦12,500',
            'store_name' => 'Ladies Corner',
        ], ['order_id' => 42]);

        self::assertNotNull($id);

        $row = $this->store->db->first(
            sprintf('SELECT * FROM %smessage_log WHERE id = ?', $this->store->db->prefix()),
            [$id]
        );

        self::assertSame('queued', $row['status']);
        self::assertSame('email', $row['channel']);
        self::assertSame(42, (int) $row['order_id']);
        self::assertSame('Order SK-00007 received — Ladies Corner', $row['subject']);
        self::assertStringContainsString('SK-00007', (string) $row['body']);
        self::assertStringNotContainsString('{{', (string) $row['body'], 'Every placeholder is rendered before it is queued');
        self::assertCount(0, $this->store->channel->sent, 'Queueing must not call the provider');
    }

    public function testAnUnknownEventQueuesNothing(): void
    {
        self::assertNull($this->store->messages->queue('made_up_event', 'ada@example.com'));
        self::assertSame(0, $this->store->messages->pending());
    }

    public function testDispatchDeliversAndMarksTheMessageSent(): void
    {
        $this->store->messages->queue('order_created_buyer', 'ada@example.com', [
            'order_number' => 'SK-00007',
            'store_name' => 'Ladies Corner',
        ]);

        $result = $this->store->messages->dispatch();

        self::assertSame(1, $result['sent']);
        self::assertSame(0, $result['remaining']);
        self::assertCount(1, $this->store->channel->sent);
        self::assertSame('ada@example.com', $this->store->channel->sent[0]['to']);

        $row = $this->store->db->first(sprintf('SELECT * FROM %smessage_log LIMIT 1', $this->store->db->prefix()));
        self::assertSame('sent', $row['status']);
        self::assertSame('test-1', $row['provider_message_id']);
        self::assertNull($row['error']);
    }

    public function testAFailingChannelIsRetriedAndThenGivesUp(): void
    {
        $this->store->channel->failuresRemaining = 4;
        $this->store->messages->queue('order_created_buyer', 'ada@example.com', ['order_number' => 'SK-1']);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->store->messages->dispatch();
            $row = $this->store->db->first(sprintf('SELECT * FROM %smessage_log LIMIT 1', $this->store->db->prefix()));

            self::assertSame($attempt, (int) $row['attempts']);
            self::assertSame('queued', $row['status'], 'A failed attempt stays in the outbox');
            self::assertStringContainsString('refused', (string) $row['error']);
        }

        $this->store->messages->dispatch();

        $row = $this->store->db->first(sprintf('SELECT * FROM %smessage_log LIMIT 1', $this->store->db->prefix()));
        self::assertSame('sent', $row['status']);
        self::assertSame(5, (int) $row['attempts']);
    }

    public function testAMessageBecomesFailedAfterFiveAttempts(): void
    {
        $this->store->channel->failuresRemaining = 99;
        $this->store->messages->queue('order_created_buyer', 'ada@example.com', ['order_number' => 'SK-2']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->store->messages->dispatch();
        }

        $row = $this->store->db->first(sprintf('SELECT * FROM %smessage_log LIMIT 1', $this->store->db->prefix()));
        self::assertSame('failed', $row['status']);
        self::assertSame(5, (int) $row['attempts']);
    }

    public function testAQueuedMessageSurvivesWithNoChannelConfigured(): void
    {
        $this->store->messages->queue('order_created_buyer', 'ada@example.com', ['order_number' => 'SK-3']);

        $service = new \StoreKeeper\Commerce\Notifications\MessageService(
            $this->store->templateRepository,
            $this->store->messageLog,
            [],
            $this->store->clock
        );

        $result = $service->dispatch();

        self::assertSame(1, $result['failed']);
        self::assertSame(0, $result['remaining'], 'A missing channel fails the message instead of looping forever');
    }

    public function testAnOrderQueuesTheBuyerAndStaffMessages(): void
    {
        $order = [
            'id' => 11,
            'number' => 'SK-00011',
            'customer_id' => null,
            'total_kobo' => 1240000,
            'shipping_address' => json_encode([
                'recipient_name' => 'Ada Obi',
                'phone_e164' => '+2348031234567',
                'email' => 'ada@example.com',
            ]),
        ];

        $this->store->orderNotifications->orderCreated($order, 2, 'ada@example.com', 'shop@example.com', [
            'store_name' => 'Ladies Corner',
            'order_url' => 'https://shop.test/order-received/SK-00011/',
            'bank_details' => 'Zenith Bank 0123456789',
        ]);

        self::assertSame(2, $this->store->messages->pending());

        $this->store->messages->dispatch();

        self::assertCount(2, $this->store->channel->sent);
        self::assertStringContainsString('Ada Obi', $this->store->channel->sent[0]['body']);
        self::assertStringContainsString('Zenith Bank', $this->store->channel->sent[0]['body']);
        self::assertSame('New order SK-00011', explode(' — ', $this->store->channel->sent[1]['subject'])[0]);
    }

    public function testAnOrderWithoutABuyerEmailOnlyAlertsTheStore(): void
    {
        $order = [
            'id' => 12,
            'number' => 'SK-00012',
            'customer_id' => null,
            'total_kobo' => 850000,
            'shipping_address' => json_encode(['recipient_name' => 'Walk-in', 'phone_e164' => '+2348030000000']),
        ];

        $this->store->orderNotifications->orderCreated($order, 1, null, 'shop@example.com', [
            'store_name' => 'Ladies Corner',
            'order_url' => 'https://shop.test/order-received/SK-00012/',
            'bank_details' => '',
        ]);

        self::assertSame(1, $this->store->messages->pending());
    }
}
