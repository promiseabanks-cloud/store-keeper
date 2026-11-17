# Phase 7 report — Notifications (email outbox)

Covers the notification part of brief §22.4 / §12: templates, a durable outbox,
delivery attempts and the first channel. Internal phase count; the brief numbers
this inside §22.4.

## What shipped

### 1. Templates

`Notifications\Templates` holds defaults for `order_created_buyer`,
`order_created_staff` and `otp_login` (the last is defined but not yet routed —
see below). A store overrides any event by saving a row in
`{p}message_templates`; otherwise the default is used, so a new store sends a
correct confirmation with no setup. Placeholders are `{{name}}`; an unsupplied
placeholder is removed rather than shown to a customer.

### 2. Outbox, not in-request sending

`MessageService::queue()` renders the message and writes it to `message_log` as
`queued`. `dispatch()` runs later (hourly WP-Cron event
`storekeeper_dispatch_messages`, or `wp storekeeper messages dispatch`) and is the
only place a channel is called. A failed send increments `attempts`, keeps the
row queued and retries; after five attempts the row is `failed`. A channel with
no adapter fails its message immediately instead of looping (D-032).

Migration `0009_notifications` adds `subject` to templates and `subject`, `body`,
`attempts` plus a `(status, created_at)` index to the log, because the brief's
DDL has nowhere to keep a rendered message or its try count.

### 3. Email channel

`EmailChannel` uses `wp_mail`, so no provider is needed. `LogChannel` writes the
message to the PHP error log and counts it delivered; a store selects it with the
`notification_transport` option set to `log`, which is how the preview (no mail
server) demonstrates the outbox. It is preview-only and documented as such
(D-033).

### 4. Order notifications

The plugin listens on `storekeeper/order/created` and queues:

- a confirmation to the buyer's email, taken from the order's contact details or
  the signed-in customer's stored email;
- a new-order alert to `notification_email` (falling back to the WordPress admin
  address).

Checkout now has an optional email field, validated with
`FILTER_VALIDATE_EMAIL` before any order exists, stored on the order's contact
details. Nothing is sent during checkout.

### 5. WP-CLI

`wp storekeeper messages dispatch [--limit=<n>]`.

**Bug fixed:** both this command and the previously shipped
`wp storekeeper orders expire-holds` failed with "Too many positional arguments"
because they were registered as one command whose `__invoke` inspected `$args`.
WP-CLI needs one method per subcommand; both are now registered as explicit
callable subcommands, and the documented invocations work.

## Tests

`tests/Integration/NotificationsTest.php` — 8 tests: queue renders and stores the
message without calling a channel, unknown event queues nothing, dispatch sends
and marks sent, a failing channel retries then succeeds, five failures make the
row `failed`, a missing channel fails rather than loops, an order queues both
buyer and staff messages, and an order without a buyer email only alerts the
store. `CheckoutTest` gains two: the email is stored lower-cased and a bad email
is refused before any order exists.

Full suite: **167 tests, 1,512 assertions, OK** (`tools/preview/run-tests.sh`).

## Live verification (preview)

With `notification_transport=log` and `notification_email=shop@example.test`, a
guest checkout with `ada@example.test` created order `SK-00003` and queued two
rows (buyer + staff, both `email`, `queued`, with rendered subjects). Running
`wp storekeeper messages dispatch` reported `2 sent, 0 failed, 0 still queued`,
and both rows became `sent` with `attempts=1`. `wp storekeeper orders
expire-holds` now runs instead of erroring.

**Not verified:** real email delivery (`wp_mail` has no mail server in the
preview), WhatsApp/SMS, template/log screens, and clicking through the UI (HTTP
only).

## Decisions and questions

- D-032 outbox in `message_log` with rendered bodies and retries; D-033 email
  first with a preview-only log transport.
- Q-017 (new): sender address/domain and whether a transactional email provider is
  wanted for deliverability.
- Q-016 unchanged: OTP delivery is the same missing provider decision; the
  `otp_login` template exists so the adapter only has to queue it.

## Next

Account area (order history, addresses) and wholesale registration, then the
WhatsApp/SMS channels once Q-016 is answered, then shipments and public tracking.
