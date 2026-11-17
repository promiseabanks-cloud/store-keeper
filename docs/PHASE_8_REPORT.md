# Phase 8 report — Sign-up and the account area

Covers the remaining customer-facing part of brief §22.5 and §11.1/§11.2.
Internal phase count; the brief numbers this inside §22.5.

## What shipped

### 1. Wholesale self-registration (`/register/`)

The form asks for a name, a phone number, "How are you buying?" — the choices are
the shop's self-registerable customer groups — an optional business name (required
when the chosen group is wholesale) and an optional email.

The number is confirmed with a 6-digit code **before** the customer row is written
(D-034), so a mistyped or invented number never becomes a customer and
"already registered" is the error a returning shopper sees. On confirmation the
browser is signed in, any cart picked before signing up is claimed, and the
shopper lands in the shop or at checkout.

Approval follows the group:

- `approval = auto` — approved immediately, group prices apply at once;
- `approval = manual` — customer sits in the default retail group with
  `approval_status = pending` and `requested_group_id` set, exactly as §11.1
  requires, and the account page says the trade account is awaiting approval.

The code is delivered by the same sender as sign-in, so it is currently the log
sender (Q-016); the preview screen shows it under the documented switches.

### 2. Account page (`/account/`)

Signed-in only; a guest is redirected to `/sign-in/`. It shows:

- the customer's orders, newest first, with number, date, status and total, a link
  to the token-protected order page and "Order again";
- saved addresses read from the customer's address book;
- the pending-approval notice when there is one;
- sign out.

Orders are listed through `StorefrontAccount::orders()`, which is scoped by
customer id — the account screen has no query of its own.

### 3. Repeat order

`StorefrontAccount::repeat()` re-adds the old order's lines to the current cart at
today's prices, skipping anything no longer sellable and naming it in the notice
(D-035). It refuses an order belonging to another customer even when the number is
guessed, and it never copies old prices or creates an order directly.

### 4. Supporting changes

- `StorefrontAuth` grew `requestRegistration()`, `verifyRegistration()` and
  `openSession()`, and code checks are now scoped by purpose, so a sign-in code
  cannot confirm a sign-up.
- `CustomerRepository::selfRegisterableGroups()` backs the sign-up choices.
- Routes `/register/` and `/account/`, titles, and account styles.
- `docs/INTEGRATIONS.md` lists every credential still needed and where it plugs in.

## Tests

`tests/Integration/StorefrontAccountTest.php` — 9 tests: sign-up choices come from
self-registerable groups; auto approval keeps the wholesale group; manual approval
leaves the shopper pending in retail with `requested_group_id`; a registered number
cannot sign up twice; wholesale needs a business name while retail does not; the
account lists only that customer's orders; repeating refills the cart; repeating
skips an archived product; another customer's order cannot be repeated.

Full suite: **177 tests, 1,538 assertions, OK** (`tools/preview/run-tests.sh`).

## Live verification (preview)

- `/account/` as a guest: `302` to `/sign-in/`.
- New shopper `08035550001` → code request → preview code `323319` → confirm:
  customer created **approved** in the Wholesale group with business name and
  email, `Location: /shop/`, `skf_session` issued. `/account/` rendered
  "Hello, Chidi New", "No orders yet", saved addresses.
- Existing wholesale customer signed in by OTP → `/account/` listed `SK-00002`
  with "View" and "Order again"; repeating it redirected to `/cart/` with "Added 1
  item from that order to your cart." at ₦6,200 each (the customer's group price),
  cart subtotal ₦12,400.
- No PHP warnings or fatals in the server log for these requests.

**Not verified:** a manual-approval store on the preview (covered by tests only),
real OTP delivery, editing addresses, and clicking through the UI in a browser
(HTTP only).

## Decisions

- D-034 phone verified before the customer row exists; approval follows the group.
- D-035 repeat order is a cart refill at today's prices, never a copied order.

## Next

WhatsApp/SMS adapters once Q-016 is answered, shipping zones and shipment
tracking, then the POS and platform layers. `docs/INTEGRATIONS.md` is the checklist
for every credential still missing.
