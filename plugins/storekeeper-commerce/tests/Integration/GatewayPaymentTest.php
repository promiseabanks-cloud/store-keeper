<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Alerts\AlertService;
use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Exception\GatewayError;
use StoreKeeper\Commerce\Orders\OrderStatus;
use StoreKeeper\Commerce\Orders\PaymentStatus;
use StoreKeeper\Commerce\Payments\Gateway\GatewayConfig;
use StoreKeeper\Commerce\Payments\Gateway\GatewayRegistry;
use StoreKeeper\Commerce\Payments\PaymentRepository;
use StoreKeeper\Commerce\Payments\PaymentService;
use StoreKeeper\Commerce\Tests\Support\FakeGatewayConfigs;
use StoreKeeper\Commerce\Tests\Support\FakeHttpClient;
use StoreKeeper\Commerce\Tests\Support\TestStore;

/**
 * Gateway money (§13.2): a checkout link, a server-side verify, and a webhook
 * that is never trusted on its own.
 */
final class GatewayPaymentTest extends TestCase
{
    private TestStore $store;

    private FakeHttpClient $http;

    private FakeGatewayConfigs $configs;

    private PaymentService $payments;

    private int $variantId;

    private int $locationId;

    private const SECRET = 'sk_test_1234567890';

    protected function setUp(): void
    {
        $this->store = TestStore::create();
        $this->store->seedGroups();
        $this->locationId = (int) $this->store->seedLocation()['id'];
        $this->variantId = (int) $this->store->seedProduct($this->locationId, 500000, 20)['variant']['id'];

        $this->http = new FakeHttpClient();
        $this->configs = new FakeGatewayConfigs();
        $this->configs->add(new GatewayConfig(
            slug: 'paystack',
            label: 'Paystack',
            enabled: true,
            testMode: true,
            publicKey: 'pk_test_123',
            secretKey: self::SECRET,
        ));

        $this->payments = new PaymentService(
            $this->store->db,
            new PaymentRepository($this->store->db, $this->store->clock),
            $this->store->orders,
            new GatewayRegistry($this->configs, $this->http),
            $this->store->alerts,
            $this->store->audit,
            $this->store->clock,
            'https://shop.test/wp-json/storekeeper/v1/webhooks',
            'https://shop.test',
        );
    }

    public function testInitializeCreatesAPendingPaymentAndReturnsTheCheckoutUrl(): void
    {
        $order = $this->openOrder();
        $this->http->queue(200, [
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/abc123', 'reference' => 'whatever'],
        ]);

        $link = $this->payments->initialize((string) $order['uuid'], 'paystack', $this->store->actor());

        self::assertSame('https://checkout.paystack.com/abc123', $link['redirect_url']);
        self::assertSame(1000000, $link['amount_kobo']);
        self::assertStringStartsWith((string) $order['number'] . '-PAYSTACK-', $link['reference']);

        $request = $this->http->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://api.paystack.co/transaction/initialize', $request['url']);
        self::assertSame('Bearer ' . self::SECRET, $request['headers']['Authorization']);
        $sent = json_decode((string) $request['body'], true);
        self::assertSame(1000000, $sent['amount'], 'Paystack is told kobo');
        self::assertSame('https://shop.test/?order=' . $order['number'], $sent['callback_url']);
        self::assertSame((string) $order['number'], $sent['metadata']['order_number']);

        $row = $this->store->db->first(
            sprintf('SELECT * FROM %spayments WHERE gateway_reference = ?', $this->store->db->prefix()),
            [$link['reference']]
        );
        self::assertSame('pending', $row['status']);
        self::assertSame('paystack', $row['method']);

        // A pending gateway payment must not count as money.
        $unchanged = $this->store->orders->find((int) $order['id']);
        self::assertSame(PaymentStatus::UNPAID, $unchanged['payment_status']);
    }

    public function testAConfiguredGatewayThatRefusesLeavesThePaymentFailed(): void
    {
        $order = $this->openOrder();
        $this->http->queue(400, ['status' => false, 'message' => 'Invalid key']);

        $this->expectException(GatewayError::class);

        try {
            $this->payments->initialize((string) $order['uuid'], 'paystack', $this->store->actor());
        } finally {
            $rows = $this->store->db->select(
                sprintf('SELECT status FROM %spayments WHERE order_id = ?', $this->store->db->prefix()),
                [(int) $order['id']]
            );
            self::assertSame('failed', $rows[0]['status']);
        }
    }

    public function testAVerifiedWebhookCreditsTheOrderExactlyOnce(): void
    {
        $order = $this->openOrder();
        $reference = $this->startCheckout($order);

        $this->http->queue(200, [
            'status' => true,
            'data' => ['status' => 'success', 'reference' => $reference, 'amount' => 1000000, 'currency' => 'NGN'],
        ]);

        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $reference]], JSON_THROW_ON_ERROR);
        $headers = ['x-paystack-signature' => hash_hmac('sha512', $body, self::SECRET)];

        $first = $this->payments->handleWebhook('paystack', $body, $headers);
        self::assertTrue($first['handled']);
        self::assertSame('succeeded', $first['status']);

        $paid = $this->store->orders->find((int) $order['id']);
        self::assertSame(PaymentStatus::PAID, $paid['payment_status']);
        self::assertSame(1000000, (int) $paid['paid_kobo']);
        self::assertSame(OrderStatus::OPEN, $paid['status'], 'Payment still does not hand the goods over');
        self::assertSame(2, (int) $this->store->inventory->level($this->variantId, $this->locationId)['reserved']);

        // A replayed callback must not verify again nor credit twice.
        $callsBefore = count($this->http->requests);
        $second = $this->payments->handleWebhook('paystack', $body, $headers);

        self::assertSame('already_succeeded', $second['status']);
        self::assertCount($callsBefore, $this->http->requests);
        self::assertSame(1000000, (int) $this->store->orders->find((int) $order['id'])['paid_kobo']);
    }

    public function testABadSignatureIsLoggedAndCreditsNothing(): void
    {
        $order = $this->openOrder();
        $reference = $this->startCheckout($order);

        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $reference]], JSON_THROW_ON_ERROR);
        $result = $this->payments->handleWebhook('paystack', $body, ['x-paystack-signature' => str_repeat('a', 128)]);

        self::assertTrue($result['ok']);
        self::assertFalse($result['handled']);
        self::assertSame(PaymentStatus::UNPAID, $this->store->orders->find((int) $order['id'])['payment_status']);
        self::assertSame(1, $this->store->alerts->openCount(AlertService::WEBHOOK_FAILURE));
        // No verify call was made from unverified data.
        self::assertCount(1, $this->http->requests);
    }

    public function testAShortPaymentRaisesAMismatchAlertAndLeavesABalance(): void
    {
        $order = $this->openOrder();
        $reference = $this->startCheckout($order);

        $this->http->queue(200, [
            'status' => true,
            'data' => ['status' => 'success', 'reference' => $reference, 'amount' => 400000, 'currency' => 'NGN'],
        ]);

        $webhook = $this->signPaystack(['event' => 'charge.success', 'data' => ['reference' => $reference]]);
        $result = $this->payments->handleWebhook('paystack', $webhook['body'], $webhook['headers']);

        self::assertSame('succeeded', $result['status']);
        self::assertSame(1, $this->store->alerts->openCount(AlertService::PAYMENT_MISMATCH));

        $paid = $this->store->orders->find((int) $order['id']);
        self::assertSame(PaymentStatus::PARTIALLY_PAID, $paid['payment_status']);
        self::assertSame(400000, (int) $paid['paid_kobo']);
    }

    public function testAWebhookForAnUnknownReferenceIsIgnoredWithoutCallingTheGateway(): void
    {
        $this->openOrder();
        $webhook = $this->signPaystack(['event' => 'charge.success', 'data' => ['reference' => 'NOPE-123']]);

        $result = $this->payments->handleWebhook('paystack', $webhook['body'], $webhook['headers']);

        self::assertTrue($result['handled']);
        self::assertSame('unknown_reference', $result['status']);
        self::assertSame([], $this->http->requests);
    }

    public function testFlutterwaveAcceptsItsSecretHashAndRejectsAnythingElse(): void
    {
        $this->configs->add(new GatewayConfig(
            slug: 'flutterwave',
            label: 'Flutterwave',
            enabled: true,
            testMode: true,
            publicKey: 'FLWPUBK_TEST',
            secretKey: 'flw-hash-abc',
        ));

        $order = $this->openOrder();
        $adapter = (new GatewayRegistry($this->configs, $this->http))->get('flutterwave');

        self::assertTrue($adapter->verifyWebhook('{}', ['verif-hash' => 'flw-hash-abc']));
        self::assertFalse($adapter->verifyWebhook('{}', ['verif-hash' => 'wrong']));
        self::assertFalse($adapter->verifyWebhook('{}', []));

        $this->http->queue(200, ['status' => 'success', 'message' => 'Hosted Link', 'data' => ['link' => 'https://checkout.flutterwave.com/v3/hosted/pay/x']]);
        $link = $this->payments->initialize((string) $order['uuid'], 'flutterwave', $this->store->actor());

        self::assertSame('https://checkout.flutterwave.com/v3/hosted/pay/x', $link['redirect_url']);
        self::assertSame(1000000, $link['amount_kobo']);
        // Flutterwave takes major units, so 10,000 NGN goes out as 10000.00.
        self::assertSame('10000.00', json_decode($this->http->requests[0]['body'], true)['amount']);
    }

    public function testA200WithAnErrorEnvelopeIsReportedAsAGatewayError(): void
    {
        $this->configs->add(new GatewayConfig(
            slug: 'opay',
            label: 'OPay',
            enabled: true,
            testMode: true,
            publicKey: 'pk_opay',
            secretKey: 'sk_opay',
            merchantId: 'M-1',
        ));

        $this->http->queue(200, ['code' => '02001', 'message' => 'http message not readable']);

        try {
            $this->payments->initialize((string) $this->openOrder()['uuid'], 'opay', $this->store->actor());
            self::fail('An OPay error envelope must not produce a checkout');
        } catch (GatewayError $error) {
            self::assertStringContainsString('http message not readable', $error->getMessage());
        }

        $this->http->queue(200, ['status' => false, 'message' => 'Invalid key']);

        $this->expectException(GatewayError::class);
        $this->expectExceptionMessage('Invalid key');
        $this->payments->initialize((string) $this->openOrder()['uuid'], 'paystack', $this->store->actor());
    }

    /** @return array<string,mixed> */
    private function openOrder(): array
    {
        return $this->store->orders->create([
            'channel' => 'whatsapp',
            'payment_method' => 'paystack',
            'idempotency_key' => uniqid('gw-test', true),
            'lines' => [['variant_id' => $this->variantId, 'qty' => 2]],
        ], $this->store->actor())['order'];
    }

    /** @param array<string,mixed> $order */
    private function startCheckout(array $order): string
    {
        $this->configs->add(new GatewayConfig(
            slug: 'paystack',
            label: 'Paystack',
            enabled: true,
            testMode: true,
            publicKey: 'pk_test_123',
            secretKey: self::SECRET,
        ));

        $this->http->queue(200, [
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/abc123'],
        ]);

        return $this->payments->initialize((string) $order['uuid'], 'paystack', $this->store->actor())['reference'];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{body:string,headers:array<string,string>}
     */
    private function signPaystack(array $payload): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return [
            'body' => $body,
            'headers' => ['x-paystack-signature' => hash_hmac('sha512', $body, self::SECRET)],
        ];
    }
}
