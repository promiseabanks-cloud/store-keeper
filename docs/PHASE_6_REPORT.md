# Phase 6 report — Storefront sign-in (phone + OTP)

Covers brief §22.5 (storefront account) and the OTP/session rules in §7.4, §8.2.
Internal phase count; the brief numbers this work inside §22.5.

## What shipped

### 1. Sign-in service

- `Customers\StorefrontAuth` — request a code, verify it, resolve a session, sign
  out one browser or every browser.
- Codes: 6 digits, bcrypt hash at rest, 10-minute expiry, single use. Five wrong
  guesses retire a code. Five codes per phone per hour and twenty per IP per hour;
  the IP is recorded by migration `0008_otp_ip`.
- Requests for unknown or rejected numbers get the same answer as known ones, so
  sign-in does not reveal which numbers have accounts. No code is created for them.
- Sessions: 32 random bytes in `skf_session` (HTTP-only, Secure on HTTPS,
  SameSite=Lax); the database stores only the SHA-256. 90-day lifetime, extended
  once fewer than 60 days remain. Sign-out deletes the row; "everywhere" deletes
  all rows for the customer.

### 2. Delivery seam

`Storefront\Otp\OtpSender` is the provider interface. `LogOtpSender` is the only
implementation until Q-005/Q-006 are answered: it writes the code to the PHP error
log and keeps it in a 10-minute transient. The sign-in screen shows the code only
when `WP_DEBUG` and the `storekeeper_otp_show_code` option are both on (D-030).

### 3. Screens

`/sign-in/` is a rewrite rule and a server-rendered template with three states:
phone form, code form (number masked, resend, start again) and signed-in state with
sign-out. The header links to it ("Sign in" / "Hi, Ada"). Checkout shows that group
prices are applied when signed in, and offers sign-in when not.

Three nonce-checked `admin-post.php` actions: `storekeeper_otp_request`,
`storekeeper_otp_verify`, `storekeeper_sign_out`. The shared nonce failure handling
moved into `Storefront\FormNonce`, which cart/checkout now use too.

### 4. Wholesale checkout

`CustomerContext` resolves the session cookie, so `CatalogView`, `CartService` and
`CheckoutService` price from the customer's group without further changes. Checkout
passes `customer_id`; `OrderService` records `customer_group_id` at order time.

### 5. Cart merge on sign-in

`CartService::claim()` attaches the browsing cart and folds in any active cart the
customer left on another device, capping quantities at available stock and marking
the other carts `abandoned` (D-031).

## Tests

`tests/Integration/AuthTest.php` — 11 tests: hashed code, unknown-number answer,
session creation and hashing, single use, five attempts, expiry, per-phone rate
limit, sign-out everywhere, sliding expiry, cart merge, and a signed-in wholesale
checkout.

Full suite: **157 tests, 1468 assertions, OK** (`tools/preview/run-tests.sh`).

## Live verification (preview)

Against the rebuilt preview (`docs/PREVIEW.md`), driven with `curl` and a fixed
cookie header:

- `/ladies-corner/sign-in/` renders the phone form;
- requesting a code for the seeded customer `0803 123 4567` returns 302, sets the
  challenge cookie and the code form shows `+234•••4567`;
- the preview code (displayed because both switches are on) verifies, sets
  `skf_session`, and the header changes to "Hi, Ada";
- the product page shows ₦6,200 (wholesale) instead of ₦8,500 (retail);
- a signed-in checkout created order `SK-00002`: `customer_id=1`,
  `customer_group_id=2`, subtotal ₦12,400 (2 × ₦6,200), total ₦12,400 on pickup;
- sign-out returns 302 and the header shows "Sign in" again.

**Not verified:** delivery of a real code (no provider), registration, account
pages, and the browser UI was exercised through HTTP, not by clicking.

## Decisions and questions

- D-029 OTP storage and limits, D-030 delivery seam and preview switch, D-031 cart
  merge on sign-in.
- Q-016 (new): which channel sends the code, and from which sender/number. This is
  the only thing between the feature and a shopper using it on the live site.
- Q-012 updated: wholesale checkout is now possible behind sign-in; guest checkout
  is unchanged.

## Next

Account area (order history, addresses) and the wholesale registration form, then
the notification adapters (§22.4) — which also remove the OTP delivery blocker.
