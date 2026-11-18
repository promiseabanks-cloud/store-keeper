# Point of sale and offline sync

Phase 5 of the brief (§15, §22.6). The engine is `StoreKeeper\Commerce\Pos`; the
app is served at `/pos/`.

## What exists

| Piece | Where |
|---|---|
| Device registration, device tokens, revoke | `src/Pos/DeviceService.php`, `/pos/devices` |
| Cashier PIN (4–6 digits, hashed, 15-minute lockout) | `DeviceService::setPin()/verifyPin()`, `/pos/pins`, `/pos/cashier/verify` |
| Sell screen, cart, payment method, receipt | `/pos/`, `assets/pos/pos.js` |
| Counter sale (stock leaves immediately) | `CounterService::sale()` |
| Parked sale / resume / discard | `CounterService::hold()`, `/pos/held` |
| Counter return with optional restock | `CounterService::counterReturn()` |
| Cash-up (opening float, expected, counted, difference) | `CounterService::cashUp()` |
| Pull / push protocol | `SyncService`, `/sync/pull`, `/sync/push` |
| Change feed | `Pos\ChangeLog`, table `{p}change_log` |
| Oversell alert | `InventoryService::counterSale()` raises it, `CounterService` returns a warning |

The app is a standalone page outside the theme, with a manifest and a small
service worker, so it can be installed from the browser. It is deliberately plain
JavaScript: no build step, and every write goes through the same REST endpoints.

## Device and cashier

1. A manager signed into wp-admin opens `/pos/`, names the device and picks the
   location it sells from. The server returns a `skd_…` token **once**; only its
   SHA-256 is stored.
2. The token is kept in `localStorage` and sent as the `Device` header. Revoking
   the device in the back office (or `POST /pos/devices/{uuid}/revoke`) makes
   every request with that token `401`.
3. A cashier picks their name and enters their PIN. The PIN is hashed with
   `password_hash`; five wrong tries lock it for 15 minutes. Each sale carries
   `cashier_user_id`, stored in `{p}pos_sales` with the device.

`GET /pos/staff` only needs the device token because it returns names and whether
a PIN exists. Setting a PIN needs a WordPress user with `manage_staff`.

## Money and stock

- A counter sale goes through `OrderService::create()` with `handed_over_now`, so
  its `counter_sale` stock movements are written in the order transaction. There
  is no reservation to release afterwards.
- **A sale is always accepted.** If it drives `on_hand` below zero (or below
  `reserved`), the ledger stays truthful and an `oversell` alert is raised; the
  push result carries `oversell:<sku>` as a warning.
- Prices quoted offline are honoured. When the difference from the currently
  resolved price exceeds `storekeeper_pos_price_change_threshold_bps` (default
  500 = 5%), a `price_changed_offline` note is appended to the order.
- Split payments are recorded as separate `payments` rows. A sale with no
  payment is a credit sale: the order stays unpaid.
- Refunds and returns reduce `qty_fulfilled_pieces` and are summed into
  `payment_status` (`partially_refunded` / `refunded`).
- Cash-up expected = opening float + cash taken by that device/cashier on the
  business date − cash refunded. An offline sale is counted on
  `orders.client_created_at`, not on the sync day.

## Sync protocol

**Pull** — `GET /sync/pull?cursor=` with the device token. It returns:

- `cursor` (newest `change_log` id), `full_resync_required`
- `catalog`, `pack_units`, `prices`, `groups`, `customer_prices`
- `stock_levels` (full on a resync, otherwise the variants touched since the cursor)
- `customer_changes`, `order_changes`
- `settings` (location, stale minutes, offline buffer, store name)

The catalog and price table are sent **in full on every pull**. A store's catalog
is small enough that this is simpler and safer than delta bookkeeping; the change
feed covers stock, customer and order changes. That is a deliberate v1 trade-off
(see `docs/DECISIONS.md` D-039); a change-log-backed catalog delta is the next
step if a store outgrows it.

**Push** — `POST /sync/push` with `{batch_uuid, operations: [...]}`:

- A stored `batch_uuid` returns the stored result with `replayed: true`.
- Each operation has a `client_uuid`; a counter sale uses it as the order's
  idempotency key, so a retried operation returns `duplicate`.
- Operations are applied in `client_created_at` order, each in its own
  transaction. A rejected operation (unknown variant, bad line) does not block
  the rest.
- Operations: `counter_sale`, `counter_return`, `cash_up`, `customer_create`.
- The result lists `applied` / `duplicate` / `rejected` per `client_uuid`, plus
  fresh stock levels for the affected variants.

## Safety rails

**Device rate limits** (D-041). Every device-token route — the counter endpoints,
`/pos/staff` and both sync routes — passes through `Pos\DeviceGuard`:

- A registered device may make **120 requests per minute** across all of them.
  Past that the response is `429 rate_limited` with `retry_after` (seconds),
  `limit` and `remaining: 0` in the error data.
- **20 failed token attempts per minute per client IP** are tolerated; the next
  one is `429` even before the token is checked. Successful requests do not
  count against this bucket, so several tablets behind one shop connection are
  unaffected.
- The fixed window lives in `pos_rate_limits` (one row per bucket), so it is
  shared by every PHP worker. The hourly `storekeeper_check_devices` tick prunes
  expired buckets.

**Storefront safety buffer** (§15.2, D-041). `offline_buffer_units` (default 0)
and `device_stale_minutes` (default 10) are settings sent to the device. While a
sellable location has an active device whose `last_sync_at` is missing or older
than the stale window, the storefront (product pages, search, cart) shows that
location's `available − buffer`, never below zero. Locations without a stale
device, and locations whose device was revoked, keep their full level.

**A pull counts as a sync** (D-042). Both `/sync/pull` and `/sync/push` refresh
`last_sync_at`, so a tablet that only reads stock — nothing to push yet — is not
stale and keeps its location fully sellable. The stale lookup filters `devices`
by `location_id` and `status`, which migration `0013_device_location_index`
indexes. The
buffer only protects display and cart quantities; the ledger itself is not
adjusted, and an offline sale still lands when the device reconnects (an
oversell raises an alert).

## What is not built

- **Thermal printer integration.** Receipts print with the browser's print
  dialog; there is no Bluetooth/USB driver and no WhatsApp image share.
- **Barcode hardware.** Search accepts a typed/scanned SKU as keyboard input; no
  camera scanner.
- **Customer credit limits** (`credit_limit_kobo`) are not enforced on credit
  sales.
- The app has **not been exercised in a real browser or on a phone** in this
  build; the engine and protocol are covered by tests, the UI is not.

## Tests

`tests/Integration/PosTest.php` (9) and `tests/Integration/SyncTest.php` (7)
cover registration/revoke, PIN lockout, counter sales, split payments, oversell,
idempotency, parked sales, cash-up, returns and the pull/push protocol.
`tests/Integration/PosSafetyTest.php` (6) covers the device and failed-token
limits, window recovery, rate-limit pruning, and the storefront buffer while a
device is fresh, stale, revoked or pull-only (including the zero floor).
