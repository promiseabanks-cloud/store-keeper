# Phase 5 report — Cart, checkout and hold expiry

Brief: §9.5 (payment holds), §10.3 (minimum order value), §18 (cart/checkout
contract), §22.4 (orders and payments), §22.5 (storefront gate).
Internal phase number: 5 (see the numbering warning in `HANDOVER.md`).

## What shipped

### 1. Payment holds expire (§9.5, §22.4)

Before this increment, `OrderService::create()` wrote `hold_expires_at` and nothing
ever read it: every unpaid order held stock forever. Now:

- `OrderRepository::expiredHolds()` selects `status = 'open'` orders whose hold has
  passed (paid and handed-over orders are excluded by the status);
- `OrderService::expireHolds()` cancels each one with `Actor::system()` and reason
  `Payment hold expired`, which releases its reservations through the same
  transaction as a manual cancel;
- an hourly WP-Cron event runs it, and `wp storekeeper orders expire-holds
  [--limit=N]` exists for operators after an outage.

### 2. Server-side cart (§22.5)

- Migration `0007_carts.php`: `carts` (uuid, token hash, optional customer, status,
  converted order, 30-day expiry) and `cart_items` (variant, pack unit, qty, unique
  per line).
- `Cart\CartService` stores choices only. Every summary re-resolves the unit price
  through `PricingService::resolve()` for the browsing customer and reads
  availability from the ledger. Adding beyond availability is refused with the real
  number left; an archived product or a variant sold out after it was added shows as
  an error instead of a stale price.
- `Storefront\CartSession` keeps a 32-byte token in an HTTP-only, Secure,
  SameSite=Lax cookie; only its SHA-256 is stored.
- `/cart/` page with plain forms (add/update/remove/clear). No JavaScript needed.

### 3. Checkout (§22.5, §7)

- `Checkout\CheckoutService` validates name, phone (`Phone::normalise`), shipping
  choice and payment method; enforces the group's `min_order_value_kobo` for the
  default or signed-in group; then calls `OrderService::create()` with
  `channel = 'web'`, so the order is re-priced and stock reserved in one
  transaction.
- Shipping: pickup is always free; a single flat delivery fee appears only when
  `storekeeper_flat_shipping_kobo` is set. Delivery requires street, LGA and state.
- Payment: bank transfer always; every configured gateway is offered and, when
  chosen, checkout calls `PaymentService::initialize()` and redirects to the hosted
  page. No gateway is faked or enabled for the test suite.
- Guest orders are created with `customer_id = NULL` (D-027) and keep contact
  details on the order. An existing customer with the same phone is never attached
  from a guest checkout.
- A converted cart remembers its order: a repeated submit returns that order instead
  of creating a second one or reserving twice.
- `/order-received/{number}/?token=…` is the confirmation page; without the order's
  public token it is a 404. It shows the real payment status and the bank details
  option, or says the store will send them — it never invents an account number.

### 4. Storefront wiring

`/cart/`, `/checkout/` and `/order-received/{number}/` are rewrite rules; the header
has a cart link with a live count; the product page gained an add-to-cart form for
the selected variant, and an honest out-of-stock note otherwise.

## Tests

`tools/preview/run-tests.sh` starts the throwaway MariaDB and runs PHPUnit:

```
OK (144 tests, 1412 assertions)
```

New coverage:

| File | Covers |
|---|---|
| `CartTest` (9 tests) | guest/wholesale pricing, tier re-price on quantity change, stock refusal, duplicate-line merge, archived product, sold out after add, cart isolation, remove/clear |
| `CheckoutTest` (10 tests) | guest order + reservation + cart conversion, duplicate submit, delivery fee/address, missing address, stock taken before submit, empty cart, group minimum, unknown payment method, guest phone not hijacking an account, signed-in identity |
| `HoldExpiryTest` (5 tests) | expired hold cancelled + stock released, live hold untouched, completed order untouched, no hold untouched, second run is a no-op |

## Browser and end-to-end verification

A throwaway WordPress 6.7.2 multisite preview was rebuilt against MariaDB 11.4 and
PHP 8.3 (`docs/PREVIEW.md`) and the whole flow was exercised **in the Cloud browser**
as well as with a cookie-jar `curl` script:

- product page → **Add to cart** → `/cart/` shows the item, ₦8,500 each, count badge
  and subtotal;
- quantity update to 2 re-prices and updates the subtotal; "Remove" and "Clear" work;
- adding more than the ledger holds shows `Only 40 left in stock.` and leaves the
  cart unchanged;
- `/checkout/` lists pickup (free) and delivery (₦1,500 from the option), bank
  transfer, and the live order summary;
- submitting delivery without an address redirects back to `/checkout/` with
  `Street address is required for delivery.` and creates **no** order;
- a complete guest checkout (browser and curl) created order `SK-00005` for
  ₦8,500 + ₦1,500 = ₦10,000, reserved the stock, converted the cart and redirected to
  `/order-received/SK-00005/?token=…`, which shows the bank details, total and
  delivery address;
- the confirmation page is a 404 without the order token;
- a repeated submit of a converted cart returns the same order instead of creating a
  second one;
- adding a new item after a completed order opens a fresh cart and produces a new
  order (`SK-00004` then `SK-00005`), which is the fix for the reopening bug below;
- the browser console reported no errors after the stylesheet fix.

Screenshots: `artifacts/cart.png`, `artifacts/checkout.png`,
`artifacts/order-received.png`.

### Bugs found and fixed during this pass

1. **`GatewayRegistry::configuredSlugs()` referenced a non-existent class**
   (`GatewaySettingsMap`). The checkout page lists configured gateways, so
   `/checkout/` returned a 500 until it was pointed at `GatewaySettings::GATEWAYS`.
   No test had ever called this method because no earlier screen listed gateways.
2. **A converted cart could never check out again.** `CartService::open()` returned
   the cart whatever its status, so adding a new item wrote lines into a cart that
   checkout then treated as already converted and replayed the old order. It now
   reopens a converted/abandoned cart as a clean cart with a **new uuid**, so the
   next order cannot collide with the old idempotency key. Covered by
   `CartTest::testAConvertedCartReopensAsANewCartInsteadOfReusingItsOrder`.
3. **Preview styling was invisible** (preview config, not plugin code): behind the
   TLS proxy WordPress saw a plain-HTTP request and emitted `http://` asset URLs,
   which the browser blocked as mixed content. Marking non-loopback requests as
   HTTPS in `wp-config.php` fixes it; recorded in `docs/PREVIEW.md`.

## What was not verified

- **No real gateway payment.** The gateway branch of checkout was not exercised; the
  preview has no configured gateways. OPay still lacks credentials (Q-001b).
- **No real WhatsApp/SMS or courier**, and no notification is sent by checkout.
- **No wholesale checkout in the browser**, because OTP sign-in does not exist yet;
  the signed-in path is covered by `CheckoutTest` only.
- **REST endpoints for cart/checkout were deliberately not shipped** (D-028). The
  storefront uses nonce-checked form posts to the same services.

## Decisions and questions

- D-026 cart table + cookie token.
- D-027 guest checkout without attaching an existing customer.
- D-028 storefront transport is forms, not REST, until a guest write API has auth,
  CSRF and rate limiting.
- Q-012 guest checkout, Q-013 flat delivery fee, Q-014 hold policy, Q-015 bank
  details (see `OPEN_QUESTIONS.md`).

## Next

OTP sign-in and the account area, then notifications (OTP delivery), then public
tracking and the remaining §22.4 fulfilment work.
