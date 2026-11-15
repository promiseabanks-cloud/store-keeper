# Phase 3 report — payment gateways

Scope: the gateway adapter layer and the money path from a checkout link to a
paid order (§13.1, §13.2), for Paystack, Flutterwave and OPay.

## What shipped

### Adapters

`src/Payments/Gateway/` holds one class per provider behind `GatewayAdapter`
(`GatewayAdapter.php`), with shared plumbing in `AbstractGateway`:

| Gateway | Checkout | Verification | Webhook authentication |
|---|---|---|---|
| Paystack | `POST /transaction/initialize` | `GET /transaction/verify/{ref}` | `x-paystack-signature` = HMAC-SHA512(raw body, secret) |
| Flutterwave | `POST /v3/payments` (major units) | `GET /v3/transactions/verify_by_reference?tx_ref=` | `verif-hash` equals the stored secret hash |
| OPay | `POST /api/v1/international/cashier/create` (public key + merchant id) | `POST /api/v1/international/cashier/status` (HMAC-SHA512 signature) | `signature` / `authorization` header, HMAC-SHA512 of the body (SHA3-512 and timestamp variants also accepted) |

Adapters never touch WordPress directly: transport goes through `HttpClient`
(`WordPressHttpClient` in production), which is what lets the tests drive them
without a network.

### The money path

- `POST /orders/{uuid}/payment-link` creates a `pending` payment row with the
  reference this side generated, then asks the gateway for a hosted checkout and
  returns its URL. A gateway failure leaves the row `failed` and returns `502`.
- `POST /webhooks/{gateway}` is public and authenticates by signature only.
  A webhook is never trusted for money: it names a reference, and
  `PaymentService::complete()` re-queries the gateway and credits only what the
  gateway's own status response says.
- The `(method, gateway_reference)` unique key makes a replayed webhook a no-op:
  the second delivery finds the `succeeded` row and returns
  `already_succeeded` without a second verify call or a second credit.
- Currency mismatch or an amount different from the expected amount records what
  actually arrived and raises a `payment_mismatch` alert. A bad signature or a
  failed verification raises `webhook_failure` and still answers `200`, so the
  gateway stops retrying (§13.2).
- Payment still does not move stock; handover does (§12.2).

### Back office

Orders tab: a gateway picker (only enabled gateways with saved keys) and a
**Payment link** action per unpaid order, which shows the checkout URL.

## Verification

- `vendor/bin/phpunit`: 107 tests, 1243 assertions, all passing.
  `tests/Integration/GatewayPaymentTest.php` covers: checkout creation sends kobo
  and records a pending row that does not count as money; a gateway refusal marks
  the row failed; a signed webhook credits exactly once and a replay credits
  nothing; a forged signature is logged and credits nothing; a short payment
  raises a mismatch alert and leaves a balance; an unknown reference is ignored
  without calling the gateway; Flutterwave's `verif-hash` gate and its
  major-unit amount.
- Against the preview store: both new routes registered; `payment-link` on a
  paid order → `422`; on a fresh order with a deliberately fake Paystack key →
  `502 "Paystack rejected the request"` with the row left `failed`; a forged
  webhook → `200 handled:false` with a `bad_signature` alert; a correctly signed
  webhook → signature accepted, then `502` from the fake key recorded as
  `verification_error`. This proves the wiring and both rejection paths; a real
  successful charge needs real (test-mode) keys.

## Not done yet (and why)

- **Live gateway test.** No sandbox keys are configured, so no real checkout has
  been created or paid. Paste Paystack/Flutterwave/OPay test keys into
  **Payment gateways** and the same flows run for real.
- **OPay merchant ID.** The OPay adapter is written but cannot be tried until the
  Owner supplies the merchant ID with its public and secret keys (Q-001b). A call
  to OPay's test host with placeholder keys was answered
  `{"code":"02001","message":"http message not readable"}`, which the adapter now
  surfaces verbatim instead of "no checkout URL". Whether that code is the fake
  credentials or a body-shape difference cannot be told without real keys, so the
  OPay request shape stays unverified.
- **Virtual accounts, refunds, fee pass-through on order totals, and the 15-minute
  reconciliation job** are not in this slice. `supports()` reports virtual
  accounts and refunds as absent rather than pretending; `feeKobo()` computes
  Paystack's and Flutterwave's published fee but nothing adds it to an order yet.
- **Webhook reachability.** The preview site is not reachable from the internet,
  so gateways cannot call it. The endpoint is live and tested through
  `rest_do_request`; registering the URL in each gateway dashboard happens when
  the site is deployed.
