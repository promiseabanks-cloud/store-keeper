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

## What was not verified

- **No browser pass.** The preview WordPress install was not rebuilt in this
  environment, so the cart, checkout and confirmation templates were syntax-checked
  and their services tested, but the pages were never rendered. They are the first
  thing to look at when the preview is restored (`wp rewrite flush` is required for
  the new routes).
- **No real gateway payment.** The gateway branch of checkout was not exercised; the
  test checkout is wired with no configured gateways. OPay still lacks credentials
  (Q-001b).
- **No real WhatsApp/SMS or courier**, and no notification is sent by checkout.
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
