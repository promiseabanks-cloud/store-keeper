# REST API reference

Namespace: `storekeeper/v1` (from `Core/Brand::REST_NAMESPACE`). JSON only.

## Conventions (§18)

- **Auth**: staff — WordPress cookie + `X-WP-Nonce`, or application passwords; devices — `Authorization: Device {token}`; customers — Store Keeper session cookie; webhooks — provider signature only.
- **Idempotency**: every `POST`/`PUT`/`PATCH`/`DELETE` requires an `Idempotency-Key` header (UUID). Same key + same body returns the stored response (`200`, header `Idempotency-Replayed: true`). Same key + different body returns `409 idempotency_conflict`.
- **Errors**: `{ "code": "insufficient_stock", "message": "...", "data": { "status": 409, ... } }` with stable codes.
- **Pagination**: cursor-based, `?cursor=…&limit=50` (max 200), responses include `next_cursor`.
- **IDs** in URLs and payloads are UUIDs. Money is an integer in kobo with a `currency` field.

## Phase 1 status

The engine and its HTTP-independent services are complete for this phase. REST controllers are registered in Phase 2 alongside the back office (`Rest/` directory), because §22.2 defines the phase gate as the engine — not the endpoints — and §18 requires each endpoint to be documented here as it ships.

| Area | Endpoints | Status |
|---|---|---|
| Catalog | `/products`, `/products/{id}`, `/catalog/search`, `/categories`, `/options`, `/pack-units` | Service ready (`Catalog\CatalogServiceTest`); routes in Phase 2 |
| Pricing | `/price-lists`, `/pricing/quote`, `/pricing/bulk-adjust` | Resolution engine ready (`Pricing\PricingService`); routes in Phase 2 |
| Inventory | `/stock`, `/stock/movements`, `/stock/receipts`, `/stock/adjustments`, `/stock/transfers`, `/stock/counts`, `/locations` | Engine ready (`Inventory\InventoryService`); routes in Phase 2 |
| Customers | `/customers`, `/customer-groups`, `/customers/{id}/approve` | Engine ready (`Customers\CustomerService`); routes in Phase 2 |
| Orders | `/orders` (GET/POST), `/orders/{uuid}`, `/orders/{uuid}/payments`, `/orders/{uuid}/cancel` | Shipped in Phase 2 |
| Storefront auth, cart, checkout | `/auth/*`, `/cart`, `/checkout` | Phase 4 |
| Payments | `/orders/{uuid}/payment-link`, `/webhooks/{gateway}` | Shipped in Phase 3 (checkout, verify, signed webhooks); virtual accounts and refunds are not built yet |
| Fulfilment | `/shipments/*`, `/track/{number}` | Phase 4 |
| POS / sync | `/devices/*`, `/sync/pull`, `/sync/push` | Phase 5 |
| Imports, notifications, alerts, reports, settings, staff, platform | full list in brief §18 | Phases 2–6 |

Error codes defined so far (mapped to HTTP status in `Rest\ErrorMapper` when controllers land):

| Code | HTTP | Thrown by |
|---|---|---|
| `insufficient_stock` | 409 | `Inventory\Exception\InsufficientStock` |
| `price_not_found` | 422 | `Pricing\Exception\PriceNotFound` |
| `invalid_transition` | 409 | `Orders\Exception\InvalidTransition` |
| `idempotency_conflict` | 409 | `Core\Idempotency\DuplicateRequest` |
| `not_found` | 404 | `Core\Exception\NotFound` |
| `validation_failed` | 422 | `Core\Exception\ValidationFailed` |
| `invalid_phone` | 422 | `Core\Phone::normalise()` |
| `plan_limit_reached` | 402 | `Platform\PlanGate` (Phase 6) |

## Orders (shipped)

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/orders` | `create_orders` | `?status=&payment_status=&search=&limit=`; includes customer name and phone. |
| POST | `/orders` | `create_orders` | Body: `channel`, `customer{name,phone}` or `customer_id`, `lines[{variant_id,qty}]`, `payment_method`, `handed_over_now`, optional `location_id`, `discount_kobo`. Honours `Idempotency-Key`. `409 insufficient_stock` when a line cannot be reserved. |
| GET | `/orders/{uuid}` | `create_orders` | Order, items and payment rows. |
| POST | `/orders/{uuid}/payments` | `confirm_payments` | Body: `method`, optional `amount_kobo`, `reference`, `note`. Defaults to the outstanding balance; overpayment is refused. |
| POST | `/orders/{uuid}/cancel` | `cancel_orders` | Body: `reason`. Releases reservations. |

## Payments (shipped)

| Method | Path | Auth | Notes |
|---|---|---|---|
| POST | `/orders/{uuid}/payment-link` | `create_orders` | Body: `gateway` = `paystack` \| `flutterwave` \| `opay`. Creates a `pending` payment row for the outstanding balance and returns `{gateway, reference, redirect_url, amount_kobo}`. `422` when the gateway is not enabled or has no keys; `502 gateway_error` when the gateway refuses. |
| POST | `/webhooks/{gateway}` | provider signature | Always answers `200 {ok:true, handled:bool}`. Authenticates with `x-paystack-signature` (Paystack), `verif-hash` (Flutterwave) or `signature`/`authorization` (OPay). Never credits from the body: it re-queries the gateway's status endpoint, then updates the pending row and the order's payment status. Unknown references, bad signatures and verification failures are ignored, logged to `alerts` and never create money. |

Replays are harmless: `payments` has `UNIQUE (method, gateway_reference)`, so a
second delivery of the same callback returns `already_succeeded` without a
second verify call or a second credit. A verified amount different from the
expected amount is recorded as received and raises a `payment_mismatch` alert.

Merchants register the webhook URL shown in **Payment gateways**:
`https://{store-domain}/wp-json/storekeeper/v1/webhooks/paystack`,
`…/flutterwave`, `…/opay`.
