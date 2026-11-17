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
| Storefront auth | `/auth/*` | Phase 4 (not built) |
| Storefront cart, checkout | storefront form posts (see below) | **Shipped as server-rendered forms, not REST** (D-028) |
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
| GET | `/orders/{uuid}` | `create_orders` | Order, items, payment rows and parcels (with their items and timeline). |
| POST | `/orders/{uuid}/payments` | `confirm_payments` | Body: `method`, optional `amount_kobo`, `reference`, `note`. Defaults to the outstanding balance; overpayment is refused. |
| POST | `/orders/{uuid}/cancel` | `cancel_orders` | Body: `reason`. Releases reservations. |

## Cart and checkout (shipped, storefront forms)

The storefront is server-rendered and a guest has no REST identity, so cart and
checkout are ordinary `POST`s to `admin-post.php` with a nonce, not JSON endpoints
(D-028). They call the same engine services a controller would; nothing is
computed in the template.

| Action | POST `action` | Fields | Notes |
|---|---|---|---|
| Add to cart | `storekeeper_cart` | `cart_action=add`, `variant_id`, `qty`, optional `pack_unit_id` | Refused when the quantity exceeds online availability (`Only N left`). |
| Change quantity | `storekeeper_cart` | `cart_action=update`, `line_id`, `qty` | Re-prices the line at the new tier. |
| Remove / clear | `storekeeper_cart` | `cart_action=remove\|clear`, `line_id` | |
| Place order | `storekeeper_checkout` | `name`, `phone`, `shipping=pickup\|delivery`, address fields when delivering (`line1`, `lga`, `state`, optional `line2`, `city`, `landmark`), `payment_method`, optional `note` | Creates the order at `channel=web`, reserves stock, converts the cart, then redirects to the order page or the gateway. |

- Identity: a 32-byte random token in the `skf_cart` cookie (HTTP-only, Secure,
  SameSite=Lax); only its SHA-256 is stored in `{p}carts`. The cookie carries no
  cart contents.
- Prices, product names, stock and shipping fees are resolved server-side on every
  read and again at submit. A posted `customer_group_id`, `unit_price_kobo`,
  `discount_kobo` or `location_id` is ignored.
- The cart is not a reservation: stock is held only when the order is created
  (`OrderService`), and the hold expires per §9.5 (hourly job /
  `wp storekeeper orders expire-holds`).
- A second submit of a converted cart returns the order it already created; it does
  not charge or reserve twice.
- Shipping options: once the store has configured methods, the address
  (`state` + `lga`) resolves to a zone and that zone's methods are quoted from the
  cart subtotal and weight (D-037); pickup methods are always offered. With no
  method configured, the old pickup + `storekeeper_flat_shipping_kobo` pair is
  used. `shipping` is `method:{id}` for a configured method. The address fields are
  also submitted as a **GET** to `/checkout/` (the "See delivery prices" button),
  so a fee can be quoted before the order is placed; nothing from that GET is
  trusted — `place()` re-quotes from the method config.
- Payment options: bank transfer always, plus each configured gateway (checkout
  calls `PaymentService::initialize()` and redirects to the hosted page).
- The confirmation page is `/order-received/{number}/?token={public_token}`; the
  token is required and is the only way an order is readable without staff auth.
  `/track/{number}/?t={public_token}` is the same order for a customer arriving
  from an email or WhatsApp link, with the parcel timeline (D-038).

## Shipping zones and methods (shipped)

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/shipping/zones` | `create_orders` | Zones with their areas (`state`, `lga`; `lga: null` = whole state). Ordered by `sort_order`. |
| POST | `/shipping/zones` | `manage_settings` | Body: `name`, optional `sort_order`, `areas[{state,lga}]`. An empty `areas` list makes it the catch-all. |
| GET | `/shipping/methods` | `create_orders` | Every method, active or not, with decoded `config` and `group_ids`. |
| POST | `/shipping/methods` | `manage_settings` | Body: `name`, `type` (`flat`, `weight_band`, `free_over`, `pickup`, `quote_later`), `config` (per §13.3), optional `zone_id`, `group_ids`, `is_active`, `sort_order`. A delivery method needs a zone; pickup must not have one. |
| PATCH | `/shipping/methods/{id}` | `manage_settings` | Same body; omitted fields keep their current value. |

Config validation: `flat` needs `amount_kobo`, `free_over` needs
`threshold_kobo`, `weight_band` needs at least one `{max_grams, amount_kobo}` band
and may set `over_per_kg_kobo`. `pickup` accepts `instructions`, `quote_later`
accepts `message`. Fees are integers in kobo.

## Shipments and tracking (shipped)

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/orders/{uuid}/shipments` | `create_orders` | Parcels with their lines and timeline. |
| POST | `/orders/{uuid}/shipments` | `update_shipments` | Body: optional `lines[{order_item_id,qty_pieces}]` (default: everything not packed or held by another pending parcel), optional `carrier`, `waybill_number`, `rider_name`, `rider_phone`, `method_name`, `photo_attachment_id`, `location_id`, `note`. Creates a `pending` parcel. |
| GET | `/shipments/{uuid}` | `create_orders` | One parcel with lines and events. |
| PATCH | `/shipments/{uuid}` | `update_shipments` | Body: `status` and/or dispatch details (`carrier`, `waybill_number`, `rider_name`, `rider_phone`, `method_name`, `photo_attachment_id`, `note`), plus `restock` on a return and `collected_kobo` / `collection_method` on delivery. |
| POST | `/shipments/bulk-status` | `update_shipments` | Body: `shipment_ids[]`, `status`. Returns `updated` and per-parcel `failed` messages; one bad parcel does not stop the rest. |

Lifecycle (§14.1): `pending → packed → dispatched|ready_for_pickup → delivered`, with
`failed_delivery` able to retry or return, and `cancelled` only before packing.
Packing commits that parcel's reservations; dispatch needs a carrier or method;
`failed_delivery` needs a note. Invalid moves return `409 invalid_transition`.

| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | `/track/{number}/?t={public_token}` | public token | Server-rendered page: items, totals, payment status, bank details, parcel timeline, carrier, waybill, rider phone. No token or a wrong token is a 404. |

## Sign-up and account (shipped, storefront forms)

| What | How |
|---|---|
| `/register/` | Name, phone, "How are you buying?" from self-registerable groups, optional business name/email. Posts to `admin-post.php` action `storekeeper_register_request` (nonce `storekeeper_register_request`), then the 6-digit code posts as `storekeeper_register_verify` (nonce `storekeeper_register_verify`). |
| `/account/` | Signed-in only; a guest is redirected to `/sign-in/`. Shows the customer's orders with a view link and "Order again" (`storekeeper_reorder`, nonce `storekeeper_reorder`, field `order`), saved addresses and the sign-out form. |

## Sign-in (shipped, storefront forms)

Customers sign in with a phone number and a one-time code. Customers are not
WordPress users (§7.4), so this is not WordPress auth and no `wp-login.php` cookie
is involved.

| Action | POST `action` | Fields | Notes |
|---|---|---|---|
| Request a code | `storekeeper_otp_request` | `phone` | Always answers the same way, whether or not the number has an account. Refused after 5 codes per phone or 20 per IP in an hour. |
| Verify a code | `storekeeper_otp_verify` | `code` | Reads the phone from the 10-minute `skf_otp` challenge cookie. Sets the `skf_session` cookie (32 bytes, HTTP-only, Secure, SameSite=Lax). Expired, reused or five-times-wrong codes are refused. |
| Sign out | `storekeeper_sign_out` | — | Deletes this session row and clears the cookie. |

- Only the SHA-256 of the session token is stored in `{p}customer_sessions`; the
  session lives 90 days and slides forward once it has less than 30 left.
- The code is stored as a bcrypt hash, expires after 10 minutes and is single-use.
- The code is delivered by email through `EmailOtpSender` (D-036) to the address
  on the customer's record; the request response carries that address so the page
  can say where the code went. With no address on file, or a mail transport that
  refuses the message, `LogOtpSender` writes it to the PHP error log, and the
  preview can display it when `WP_DEBUG` and `storekeeper_otp_show_code` are both
  on. The page never claims an email was sent when it was not.
- Sign-up requires an email address for the same reason, and `otp_codes.channel`
  records `email` when one was used.
- A signed-in customer's group prices apply to the shop, the cart and checkout; the
  order records `customer_id` and the group used at order time.

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

## Customers and prices (shipped)

| Method | Path | Capability | Notes |
|---|---|---|---|
| GET | `/customers` | `manage_customers` | `?search=&group_id=&approval_status=&limit=`; includes group name, spend and approval state. Phones are E.164, so search `803…`, not `0803…`. |
| POST | `/customers` | `manage_customers` | Body: `name`, `phone`, optional `business_name`, `email`, `group_id`, `notes`. |
| GET | `/customers/{uuid}` | `manage_customers` | Customer, recent orders and special prices. |
| POST | `/customers/{uuid}/approve` | `approve_wholesale` | Moves a pending applicant into the group they requested. |
| POST | `/customers/{uuid}/reject` | `approve_wholesale` | Body: optional `reason`. |
| POST | `/customers/{uuid}/group` | `approve_wholesale` | Body: `group_id`. Changing group approves the customer. |
| PUT | `/customers/{uuid}/prices` | `edit_prices` | Body: `variant_id`, `amount_kobo`, optional `pack_unit_id`, `valid_from`, `valid_to`. |
| GET | `/customer-groups` | `manage_customers` | Groups with their price list and approval mode. |
| GET | `/price-lists` | `edit_prices` | Base list first. |
| GET | `/price-lists/{id}/prices` | `edit_prices` | `?search=`; one row per tier with product and variant names. |
| PUT | `/price-lists/{id}/prices` | `edit_prices` | Body: `variant_id`, `amount_kobo`, `min_qty` (default 1), optional `pack_unit_id`. Upserts the tier. |
| POST | `/pricing/quote` | `edit_prices` | Body: `variant_id`, `qty`, optional `pack_unit_id`, `customer_uuid`. Returns the resolved unit price and which rule matched. |
| POST | `/locations` | `manage_settings` | Body: `name`, `type` (`shop`, `warehouse`, `store`, `other`), optional `address`, `sellable_online`, `is_pickup_point`, `online_priority`. |
| GET | `/staff` | `manage_staff` | Store users with role and whether they may confirm payments, plus the role list. Read-only. |
| GET | `/alerts` | `view_reports` | `?type=`; open alerts only. |
| POST | `/alerts/{id}/resolve` | `resolve_alerts` | Body: optional `resolution`. |
