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
| Orders | `/orders`, `/orders/{id}`, `/orders/{id}/cancel`, `/orders/{id}/handover` | Engine ready (`Orders\OrderService`); routes in Phase 2 |
| Storefront auth, cart, checkout | `/auth/*`, `/cart`, `/checkout` | Phase 4 |
| Payments, fulfilment | `/orders/{id}/payments`, `/shipments/*`, `/track/{number}` | Phase 3 |
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
