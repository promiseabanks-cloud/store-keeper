<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Payments;

use StoreKeeper\Commerce\Alerts\AlertService;
use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Audit\AuditLogger;
use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;
use StoreKeeper\Commerce\Core\Exception\NotFound;
use StoreKeeper\Commerce\Core\Exception\StoreKeeperException;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Orders\OrderService;
use StoreKeeper\Commerce\Orders\OrderStatus;
use StoreKeeper\Commerce\Payments\Gateway\GatewayAdapter;
use StoreKeeper\Commerce\Payments\Gateway\GatewayRegistry;

/**
 * Gateway payments end to end (§13.2).
 *
 * Two rules hold this class together:
 *   1. Money is only ever credited from a server-side verify call, never from
 *      a redirect or a webhook body.
 *   2. The (method, gateway_reference) unique key means a replayed webhook or a
 *      repeated reconcile finds the existing row and credits nothing twice.
 */
final class PaymentService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PaymentRepository $payments,
        private readonly OrderService $orderService,
        private readonly GatewayRegistry $gateways,
        private readonly AlertService $alerts,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly string $webhookBaseUrl,
        private readonly string $storeUrl,
    ) {
    }

    /**
     * Create a hosted checkout for the outstanding balance of an order and
     * return the link the customer should open.
     *
     * @return array{gateway:string,reference:string,redirect_url:string,amount_kobo:int}
     */
    public function initialize(string $orderUuid, string $slug, Actor $actor): array
    {
        $order = $this->orderService->findByUuid($orderUuid);
        if ($order === null) {
            throw new NotFound('order', $orderUuid);
        }

        if ((string) $order['status'] === OrderStatus::CANCELLED) {
            throw new ValidationFailed('Cancelled orders cannot take a payment.', ['order' => 'Cancelled.']);
        }

        $adapter = $this->gateways->get($slug);
        $outstanding = (int) $order['total_kobo'] - (int) $order['paid_kobo'];

        if ($outstanding <= 0) {
            throw new ValidationFailed('This order is already fully paid.');
        }

        $reference = sprintf(
            '%s-%s-%s',
            (string) $order['number'],
            strtoupper($slug),
            strtoupper(bin2hex(random_bytes(3)))
        );

        $paymentId = $this->payments->create([
            'order_id' => (int) $order['id'],
            'method' => $slug,
            'status' => 'pending',
            'amount_kobo' => $outstanding,
            'gateway_reference' => $reference,
            'idempotency_key' => 'gw-' . $reference,
        ]);

        $intent = [
            'reference' => $reference,
            'amount_kobo' => $outstanding,
            'currency' => (string) $order['currency'],
            'order_number' => (string) $order['number'],
            'return_url' => $this->storeUrl . '/?order=' . rawurlencode((string) $order['number']),
            'cancel_url' => $this->storeUrl . '/?order=' . rawurlencode((string) $order['number']) . '&cancelled=1',
            'callback_url' => $this->webhookBaseUrl . '/' . $slug,
            'customer' => $this->customerDetails($order),
        ];

        try {
            $started = $adapter->initialize($intent);
        } catch (StoreKeeperException $error) {
            $this->payments->markFailed($paymentId, ['error' => $error->getMessage()]);
            $this->audit->log($actor, 'payment.initialize_failed', 'order', (int) $order['id'], null, [
                'gateway' => $slug,
                'reference' => $reference,
                'error' => $error->getMessage(),
            ]);

            throw $error;
        }

        $this->payments->rememberResponse($paymentId, $started['payload']);

        $this->audit->log($actor, 'payment.initialized', 'order', (int) $order['id'], null, [
            'gateway' => $slug,
            'reference' => $reference,
            'amount_kobo' => $outstanding,
        ]);

        return [
            'gateway' => $slug,
            'reference' => $reference,
            'redirect_url' => $started['redirect_url'],
            'amount_kobo' => $outstanding,
        ];
    }

    /**
     * Ask a gateway what happened to one of our references and credit the
     * order if the gateway itself says the money arrived (§13.2).
     *
     * @return array{status:string,reference:string,order_id?:int}
     */
    public function complete(string $slug, string $reference): array
    {
        $adapter = $this->gateways->optional($slug);
        if ($adapter === null) {
            return ['status' => 'ignored', 'reference' => $reference];
        }

        $row = $this->payments->findByGatewayReference($slug, $reference);
        if ($row === null) {
            return ['status' => 'unknown_reference', 'reference' => $reference];
        }

        if ((string) $row['status'] === 'succeeded') {
            return [
                'status' => 'already_succeeded',
                'reference' => $reference,
                'order_id' => (int) $row['order_id'],
            ];
        }

        $order = $this->orderService->find((int) $row['order_id']);
        if ($order === null) {
            return ['status' => 'unknown_order', 'reference' => $reference];
        }

        $verification = $adapter->verify($reference);

        if ($verification['status'] === 'pending') {
            $this->payments->rememberResponse((int) $row['id'], $verification['payload']);

            return ['status' => 'pending', 'reference' => $reference, 'order_id' => (int) $order['id']];
        }

        if ($verification['status'] === 'failed') {
            $this->payments->markFailed((int) $row['id'], $verification['payload']);

            return ['status' => 'failed', 'reference' => $reference, 'order_id' => (int) $order['id']];
        }

        $expectedKobo = (int) $row['amount_kobo'];
        $actualKobo = (int) $verification['amount_kobo'];
        $currency = strtoupper((string) $verification['currency']);
        $expectedCurrency = strtoupper((string) $order['currency']);

        if ($currency !== '' && $currency !== $expectedCurrency) {
            $this->payments->markFailed((int) $row['id'], $verification['payload']);
            $this->alerts->raise(AlertService::PAYMENT_MISMATCH, AlertService::CRITICAL, 'order', (int) $order['id'], [
                'gateway' => $slug,
                'reference' => $reference,
                'reason' => 'currency_mismatch',
                'expected' => $expectedCurrency,
                'received' => $currency,
            ]);

            return ['status' => 'mismatch', 'reference' => $reference, 'order_id' => (int) $order['id']];
        }

        $this->payments->markSucceeded(
            (int) $row['id'],
            $actualKobo,
            $adapter->feeKobo($actualKobo),
            $verification['payload']
        );

        $updated = $this->orderService->refreshStatuses((int) $order['id'], Actor::system());

        $this->audit->log(Actor::system(), 'payment.succeeded', 'order', (int) $order['id'], null, [
            'gateway' => $slug,
            'reference' => $reference,
            'amount_kobo' => $actualKobo,
            'expected_kobo' => $expectedKobo,
        ]);

        if ($actualKobo !== $expectedKobo) {
            $this->alerts->raise(AlertService::PAYMENT_MISMATCH, AlertService::WARNING, 'order', (int) $order['id'], [
                'gateway' => $slug,
                'reference' => $reference,
                'reason' => 'amount_mismatch',
                'expected' => $expectedKobo,
                'received' => $actualKobo,
            ]);
        }

        return [
            'status' => 'succeeded',
            'reference' => $reference,
            'order_id' => (int) $order['id'],
            'payment_status' => (string) $updated['payment_status'],
        ];
    }

    /**
     * A verified webhook only names a reference; the money still comes from
     * the verify call inside complete(). A bad signature is logged and
     * answered 200 so the gateway stops retrying (§13.2).
     *
     * @param array<string,string> $headers
     * @return array{ok:bool,handled:bool,status?:string}
     */
    public function handleWebhook(string $slug, string $rawBody, array $headers): array
    {
        $adapter = $this->gateways->optional($slug);

        if ($adapter === null) {
            return ['ok' => true, 'handled' => false];
        }

        if (!$this->signatureIsValid($adapter, $rawBody, $headers)) {
            $this->alerts->raise(AlertService::WEBHOOK_FAILURE, AlertService::WARNING, 'gateway', null, [
                'gateway' => $slug,
                'reason' => 'bad_signature',
            ]);

            return ['ok' => true, 'handled' => false];
        }

        $payload = json_decode($rawBody, true);
        $reference = is_array($payload) ? $adapter->webhookReference($payload) : null;

        if ($reference === null) {
            $this->alerts->raise(AlertService::WEBHOOK_FAILURE, AlertService::WARNING, 'gateway', null, [
                'gateway' => $slug,
                'reason' => 'missing_reference',
            ]);

            return ['ok' => true, 'handled' => false];
        }

        try {
            $outcome = $this->complete($slug, $reference);
        } catch (\Throwable $error) {
            $this->alerts->raise(AlertService::WEBHOOK_FAILURE, AlertService::WARNING, 'gateway', null, [
                'gateway' => $slug,
                'reason' => 'verification_error',
                'message' => $error->getMessage(),
            ]);

            return ['ok' => true, 'handled' => false];
        }

        return ['ok' => true, 'handled' => true, 'status' => $outcome['status']];
    }

    /** @param array<string,string> $headers */
    private function signatureIsValid(GatewayAdapter $adapter, string $rawBody, array $headers): bool
    {
        try {
            return $adapter->verifyWebhook($rawBody, $headers);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string,mixed> $order
     * @return array{name:string,email:string,phone:string}
     */
    private function customerDetails(array $order): array
    {
        $customerId = (int) ($order['customer_id'] ?? 0);
        $row = $customerId > 0
            ? $this->db->first(
                sprintf('SELECT name, email, phone FROM %scustomers WHERE id = ? LIMIT 1', $this->db->prefix()),
                [$customerId]
            )
            : null;

        $email = trim((string) ($row['email'] ?? ''));

        return [
            'name' => trim((string) ($row['name'] ?? '')),
            'email' => $email !== '' ? $email : 'orders@' . $this->host(),
            'phone' => trim((string) ($row['phone'] ?? '')),
        ];
    }

    private function host(): string
    {
        $host = parse_url($this->storeUrl, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'storekeeper.local';
    }
}
