<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Checkout;

use StoreKeeper\Commerce\Cart\CartRepository;
use StoreKeeper\Commerce\Cart\CartService;
use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Core\Money;
use StoreKeeper\Commerce\Core\Phone;
use StoreKeeper\Commerce\Customers\CustomerRepository;
use StoreKeeper\Commerce\Orders\OrderService;
use StoreKeeper\Commerce\Payments\PaymentService;

/**
 * Turns a cart into an order (§22.5, §7).
 *
 * The checkout owns no money and no stock maths of its own: it validates the
 * shopper's details, decides the shipping fee, then hands the lines to
 * OrderService, which re-prices every line through PricingService and reserves
 * stock inside one transaction. A price, a tier or a stock change between the
 * cart page and the submit is therefore caught here, never trusted from the
 * browser.
 *
 * Guest checkout is deliberate (D-027): an order placed without signing in is
 * not attached to an existing customer record, so typing someone else's phone
 * number cannot put an order on their account. The phone lives on the shipping
 * address instead.
 */
final class CheckoutService
{
    /** @param list<string> $gatewaySlugs */
    public function __construct(
        private readonly CartRepository $carts,
        private readonly CartService $cartService,
        private readonly OrderService $orders,
        private readonly CustomerRepository $customerRepository,
        private readonly int $flatShippingKobo = 0,
        private readonly array $gatewaySlugs = [],
        private readonly ?PaymentService $payments = null,
    ) {
    }

    /** @return list<array{id:string,label:string,amount_kobo:int,description:string}> */
    public function shippingOptions(): array
    {
        $options = [[
            'id' => 'pickup',
            'label' => 'Pick up at the shop',
            'amount_kobo' => 0,
            'description' => 'We will let you know when your order is ready.',
        ]];

        if ($this->flatShippingKobo > 0) {
            $options[] = [
                'id' => 'delivery',
                'label' => 'Delivery',
                'amount_kobo' => $this->flatShippingKobo,
                'description' => 'One flat delivery fee across the store.',
            ];
        }

        return $options;
    }

    /** @return list<array{id:string,label:string}> */
    public function paymentOptions(): array
    {
        $options = [[
            'id' => 'bank_transfer',
            'label' => 'Bank transfer',
        ]];

        foreach ($this->gatewaySlugs as $slug) {
            $options[] = ['id' => $slug, 'label' => ucfirst($slug) . ' (card or transfer)'];
        }

        return $options;
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $customer the signed-in customer, or null for a guest
     * @return array<string,mixed>
     */
    public function place(array $input, string $tokenHash, ?array $customer): array
    {
        $cart = $this->carts->byTokenHash($tokenHash);

        if ($cart === null) {
            throw new ValidationFailed('Your cart is empty.', ['cart' => 'Add something first.']);
        }

        // A double submit lands after the cart was converted: answer with the
        // order that already exists instead of asking for payment twice.
        if ($cart['status'] === 'converted') {
            $order = $cart['converted_order_id'] === null
                ? null
                : $this->orders->find((int) $cart['converted_order_id']);

            if ($order !== null) {
                return ['order' => $order, 'items' => $this->orders->items((int) $order['id']), 'replayed' => true, 'payment' => null];
            }
        }

        $summary = $this->cartService->summary($cart, $customer);

        if ($summary['lines'] === []) {
            throw new ValidationFailed('Your cart is empty.', ['cart' => 'Add something first.']);
        }

        if ($summary['has_errors']) {
            throw new ValidationFailed(
                'Some items in your cart need attention before you can pay.',
                ['cart' => implode(' ', $summary['errors'])]
            );
        }

        $name = trim((string) ($input['name'] ?? ''));
        $phoneInput = trim((string) ($input['phone'] ?? ''));
        $phone = '';

        if ($name === '') {
            throw new ValidationFailed('Please give us your name.', ['name' => 'Required.']);
        }

        if ($phoneInput === '') {
            throw new ValidationFailed('Please give us a phone number.', ['phone' => 'Required.']);
        }

        try {
            $phone = Phone::normalise($phoneInput);
        } catch (\Throwable) {
            throw new ValidationFailed('That phone number does not look right.', ['phone' => 'Use 0803 123 4567.']);
        }

        $shippingId = (string) ($input['shipping'] ?? 'pickup');
        $shipping = array_column($this->shippingOptions(), null, 'id')[$shippingId] ?? null;

        if ($shipping === null) {
            throw new ValidationFailed('Choose how you would like to receive the order.', ['shipping' => 'Required.']);
        }

        // Contact details are kept for pickup orders too: it is the only place
        // the order stores who to call when the parcel is ready.
        $address = [
            'recipient_name' => $name,
            'phone_e164' => $phone,
            'method' => $shippingId,
        ];

        if ($shippingId === 'delivery') {
            foreach (['line1' => 'Street address', 'lga' => 'Local government', 'state' => 'State'] as $field => $label) {
                if (trim((string) ($input[$field] ?? '')) === '') {
                    throw new ValidationFailed($label . ' is required for delivery.', [$field => 'Required.']);
                }
            }

            $address += [
                'line1' => trim((string) $input['line1']),
                'line2' => $this->clean($input['line2'] ?? null),
                'landmark' => $this->clean($input['landmark'] ?? null),
                'city' => $this->clean($input['city'] ?? null),
                'lga' => trim((string) $input['lga']),
                'state' => trim((string) $input['state']),
            ];
        }

        $paymentMethod = (string) ($input['payment_method'] ?? 'bank_transfer');
        if (!in_array($paymentMethod, array_column($this->paymentOptions(), 'id'), true)) {
            throw new ValidationFailed('Choose a payment method.', ['payment_method' => 'Unsupported.']);
        }

        $minimum = $this->minimumOrderKobo($customer);
        if ($minimum > 0 && $summary['subtotal_kobo'] < $minimum) {
            throw new ValidationFailed(
                'Your group has a minimum order value.',
                ['cart' => 'The minimum is ' . (new Money($minimum))->format() . '.']
            );
        }

        $lines = [];
        foreach ($summary['lines'] as $line) {
            $lines[] = array_filter([
                'variant_id' => $line['variant_id'],
                'qty' => $line['qty'],
                'pack_unit_id' => $line['pack_unit_id'],
            ], static fn ($value): bool => $value !== null);
        }

        // The fingerprint must see the normalised values, not the typed ones.
        $input['name'] = $name;
        $input['phone'] = $phone;

        $result = $this->orders->create([
            'channel' => 'web',
            'customer_id' => $customer === null ? null : (int) $customer['id'],
            'lines' => $lines,
            'payment_method' => $paymentMethod,
            'shipping_kobo' => $shipping['amount_kobo'],
            'shipping_address' => $address,
            'customer_note' => $this->clean($input['note'] ?? null),
            'idempotency_key' => $this->idempotencyKey((string) $cart['uuid'], $input, $lines),
        ], $customer === null ? Actor::system() : Actor::customer((int) $customer['id']));

        $order = $result['order'];

        if (!$result['replayed']) {
            $this->cartService->markConverted($cart, (int) $order['id']);
        }

        $payment = null;
        if ($paymentMethod !== 'bank_transfer') {
            if ($this->payments === null) {
                throw new ValidationFailed('That payment method is not available right now.');
            }

            $payment = $this->payments->initialize(
                (string) $order['uuid'],
                $paymentMethod,
                $customer === null ? Actor::system() : Actor::customer((int) $customer['id'])
            );
        }

        return [
            'order' => $order,
            'items' => $result['items'],
            'replayed' => (bool) $result['replayed'],
            'payment' => $payment,
        ];
    }

    /** @param array<string,mixed>|null $customer */
    private function minimumOrderKobo(?array $customer): int
    {
        $group = null;

        if ($customer !== null && isset($customer['group_id'])) {
            $group = $this->customerRepository->group((int) $customer['group_id']);
        }

        $group ??= $this->customerRepository->defaultGroup();

        return $group === null ? 0 : max(0, (int) ($group['min_order_value_kobo'] ?? 0));
    }

    /**
     * @param array<string,mixed> $input
     * @param list<array<string,mixed>> $lines
     */
    private function idempotencyKey(string $cartUuid, array $input, array $lines): string
    {
        $fingerprint = [
            'name' => $input['name'] ?? null,
            'phone' => $input['phone'] ?? null,
            'shipping' => $input['shipping'] ?? null,
            'payment_method' => $input['payment_method'] ?? null,
            'note' => $input['note'] ?? null,
            'lines' => $lines,
        ];

        return sprintf(
            'web-%s-%s',
            substr($cartUuid, 0, 8),
            substr(hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR)), 0, 40)
        );
    }

    private function clean(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, 255);
    }
}
