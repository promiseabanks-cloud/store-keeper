# Handover

Rewritten 2026-11-18. Read this first, then `docs/BRIEF.md` for requirements and
`docs/DECISIONS.md` for every choice that deviates from it. Section 9 is the
short version of "what is left".

## 1. What this is

**Store Keeper** is a hosted commerce platform for Nigerian wholesalers and
retailers, built on WordPress multisite. Each merchant is a subsite with its own
store tables, staff, catalog, storefront, message outbox and POS. The engine is
plain PHP with no knowledge of HTTP or HTML; screens call a REST API; the API
calls the engine; only the engine writes to tables.

- Working name: **Store Keeper** (final name is Q-002; every name comes from
  `plugins/storekeeper-commerce/src/Core/Brand.php`).
- Brief: `docs/BRIEF.md` (2,095 lines; § references are used everywhere).
- Repo: `promiseabanks-cloud/store-keeper`, branch `main`.
- Engine: `plugins/storekeeper-commerce/`; platform: `plugins/storekeeper-platform/`.
- Theme: `theme/storekeeper-storefront/` (PHP templates + a product-grid block).

## 2. Where the build stands

| Brief phase | State | Notes |
|---|---|---|
| §22.1 Phase 0 (discovery) | Owner questions open | `docs/OPEN_QUESTIONS.md`. |
| §22.2 Phase 1 — Foundation | **Done** | Constants, money, phone, DB abstraction, schema + migrations, capabilities, REST conventions, WP-CLI, CI. |
| §22.3 Phase 2 — Back office | **Done** | Catalog, pricing resolution, stock ledger with reservations, orders, customers, locations, staff list, alerts, back-office app shell and its APIs. |
| §22.4 Phase 3 — Orders, payments, notifications, shipping | **Engine and staff screens done; no real gateway has ever run** | Paystack/Flutterwave/OPay adapters, payment links, signed webhooks, hold expiry. Notifications: six templates with per-store overrides, `message_log` outbox, buyer/staff/shipment emails, hourly dispatch, WP-CLI dispatch and now the Messages screen (log + template editor + send now). Shipping: zones/areas and all five method types, quoted at checkout, with the Shipping screen. Parcels: multiple per order, per-parcel stock commit, dispatch details, POD collection, returns with optional restock, timeline, bulk pack, public tracking page, packing slips and dispatch sheets printed from the Parcels screen (D-038, D-043). **Not built:** WhatsApp/SMS adapters, merchant alert *delivery*, courier API adapters (§13.3 v2), bank-transfer proof upload. |
| §22.5 Phase 4 — Storefront | **Mostly done** | Catalogue, search, categories, product page with swatches and group pricing, block + shortcode, OG/canonical. Cart, guest/retail checkout, phone + OTP sign-in, wholesale self-registration with approval, account page (order history, repeat order, saved addresses). Sign-in and sign-up codes are emailed (D-036). Order and tracking pages show the parcel timeline. **Not built:** WhatsApp/SMS delivery, editing a saved address, bank-transfer proof upload, product sitemap. |
| §22.6 Phase 5 — POS + offline sync | **Engine and app done; never exercised in a browser or on a tablet** | `/pos/` installable page, device tokens, cashier PINs, counter sales/returns/cash-up, parked sales, pull/push sync with batch and per-operation idempotency, oversell and device-stale alerts (D-039). Device/sync routes are rate limited and the storefront applies the offline buffer at a stale-device location (D-041, D-042). **Not built:** thermal printing, barcode camera, browser/device validation. |
| §22.7 Phase 6 — Platform layer | **Engine, console and webhook done; provisioning never run live** | Plans + PlanGate + usage, applications, tenant provisioning with a step log, invoices, dunning ladder, verified Paystack webhook, owner console with audited impersonation (D-040, `docs/PLATFORM.md`). **Not built:** Paystack charge/Plan/Subscription creation, custom domains/SSL, revenue/health panes. |

**Numbering warning.** Commit messages and `docs/PHASE_*_REPORT.md` use an
internal count that is one behind the brief: internal "Phase 2" = brief §22.3,
internal "Phase 3" = brief §22.4 payments, internal "Phase 4" = the back-office
remainder plus storefront, which the brief splits between §22.3 and §22.5. Quote
the brief section number when in doubt.

Commit trail (oldest first): `a1129bc` core → `71f82b5` WP integration →
`312ea3e` engine → `ef58166` back office + OPay + preview → `c5d38de` orders →
`1bf2be8` gateways → `22ed483` customers/prices/locations/staff/alerts →
`2b2e9e8` back-office redesign → `63acd0a` storefront → `e8d87df` handover docs
→ cart/checkout/hold expiry → notifications outbox → storefront sign-in →
wholesale sign-up/account → email OTP → shipping zones and parcels → POS and
sync → `26e88f1` POS rate limits + offline buffer → `14ae479` pull counts as a
sync → the staff screens commit (Shipping, Parcels, Messages, and this handover).

## 3. What exists today, by layer

**Engine (`src/`)**

- `Core/` — Brand, Money (round-half-up in kobo), Phone (E.164), Uuid, Clock,
  Container, `Connection` (`WpdbConnection` + `PdoConnection`), Installer,
  SchemaMigrator, Idempotency, Audit (actor passed explicitly), Hooks, Crypto.
- `Catalog/` — products, options, variants, media, categories.
- `Pricing/` — `PricingService::resolve()` is the only price source: special price
  → group tier → base tier → derived pack → `PriceNotFound`.
- `Inventory/` — ledger, reservations, receipts, transfers, counts, locations,
  availability (with the stale-device offline buffer).
- `Customers/` — customers, groups, approvals, totals, OTP sessions.
- `Orders/` — `OrderService` (create/pay/hand over/cancel/refresh), `ShipmentService`
  (parcels, timeline, bulk status, listing), repositories.
- `Payments/` — `GatewayAdapter` + Paystack/Flutterwave/OPay, `PaymentService`,
  signed webhook handling, encrypted per-store settings.
- `Shipping/` — zones, areas, five method types, quotes, group restrictions.
- `Notifications/` — templates + overrides, outbox (`message_log`), hourly
  dispatch, email/log channels, order and shipment notifications.
- `Pos/` — devices, PINs, counter sales/returns/cash-up, held sales, change feed,
  pull/push sync, rate limiter and device guard.
- `Rest/` — thin controllers (catalog, inventory, orders, shipments, shipping,
  messages, customers, pricing, alerts, staff, settings, POS, sync, webhooks).
- `Admin/Menu.php` — wp-admin menu, body class, asset loading.

**Back office (`assets/admin/`)** — `admin.js` (shell; Overview, Products, Orders,
Customers, Prices, Locations, Staff, Gateways) and `screens.js` (Parcels,
Shipping, Messages; print via `window.print()`). `docs/ADMIN.md` is the screen
reference.

**Storefront** — `theme/storekeeper-storefront/`, `src/Storefront/` (routes, view
model, cart/checkout/auth actions, cache), `templates/storefront/`, one block.

**Platform** — plans/PlanGate, applications, provisioning step log, invoices,
dunning, webhook, owner console with impersonation. Network migrations
`network/0001–0002`; store migrations `0001–0013`.

## 4. Invariants and traps (read before editing)

1. **`declare(strict_types=1);`** everywhere in `src/` and `tests/`. `$wpdb`
   returns numeric columns as strings — cast before arithmetic.
2. **SQL placeholders are `?`**, even though production is `$wpdb`.
   `WpdbConnection::prepare()` rewrites them. `%d`/`%s` works only under `$wpdb`
   and breaks the PDO test driver (this bug shipped once). `sprintf` is only for
   table names — count the `%s` placeholders, each needs its own prefix argument.
3. **Only services write.** Stock only through `InventoryService` movements;
   prices only through `PricingService::setPrice`; orders only through
   `OrderService`; parcels only through `ShipmentService`; messages only through
   `MessageService`.
4. **Prices always resolve through `PricingService::resolve()`.**
   `PricingRepository::displayAmounts()` exists only for product cards and mirrors
   the same order.
5. **Ledger locking:** lock the `stock_levels` row (`FOR UPDATE`) → insert the
   movement → apply the delta, all in one transaction; hooks fire after commit
   (D-006, D-012).
6. **Reservations:** each line reserves from one location (first sellable with
   stock, D-005). `on_hand − reserved` is availability; storefront availability
   sums only `sellable_online = 1 AND is_active = 1` locations and subtracts
   `offline_buffer_units` at a location whose active device is stale (D-041). A
   successful pull **or** push refreshes `last_sync_at` (D-042); the check is
   indexed by `devices(location_id, status)`.
7. **Hooks** are `storekeeper/{name}` (`Brand::hook()`), dispatched through
   `Core\Hooks\Hooks` (`WpHooks` production, `InMemoryHooks` tests).
8. **Storefront caching:** guest grids only, transient keyed by blog + price list
   + category + limit; bumped by `catalog/product_created`, `stock/*`, `order/*`.
   Signed-in customers bypass it. Add new state-changing hooks to
   `Storefront::register()`.
9. **Storefront routes are rewrite rules** (`sk_view`, `sk_product`,
   `sk_category`, `sk_page`, `sk_order`); `wp rewrite flush` once after deploy.
10. **The storefront theme must be activated** and must find the plugin active.
11. **wp-admin chrome is hidden only on the Store Keeper screen.** Everything is
    scoped under `body.storekeeper-app` / `.sk-root`; primary buttons need
    `body.storekeeper-app .sk-root .button.button-primary`.
12. **Block render callbacks must capture dependencies explicitly** — a static
    callback without `$view` silently got `null` (fixed in `Blocks::loadGrid`).
13. **`wp eval-file` evaluates the file**, so scripts under `tools/preview/` must
    not start with `declare(strict_types=1)`.
14. **`wp search-replace` whenever the preview hostname changes** — see
    `docs/PREVIEW.md`.
15. **No product decisions in code:** credentials, plan prices, the product name,
    provider choices and shipping fees are settings or placeholders.
16. **The cart stores choices, not money.** `CartService::summary()` re-resolves
    every line and reads availability on every request.
17. **POS writes go through the same services.** A counter sale is an
    `OrderService` order with `handed_over_now`; `client_uuid` is the order
    idempotency key and `batch_uuid` stores the push result. Offline prices are
    honoured by design (D-039).
18. **Platform tables use `networkPrefix()`;** the platform never reads a store
    table (`PlanGate` takes the count from the caller).
19. **Checkout composes, it does not compute.** `CheckoutService` validates the
    shopper and picks the shipping fee, then calls `OrderService::create()`.
20. **Printing is a browser concern.** Packing slips/dispatch sheets render
    through `wp.element.createPortal` into `.sk-print` on `document.body`; the
    print stylesheet hides the app shell. Do not add a PDF library or courier
    call to make a sheet "work" without an owner decision.

## 5. Running things

### Tests

Needs PHP 8.2+ and a real MariaDB on `127.0.0.1:3307`, database
`storekeeper_test` (D-004; SQLite is not supported). `phpunit.xml.dist` supplies
`SK_TEST_DSN`/`SK_TEST_DB_USER`/`SK_TEST_DB_PASS`.

```bash
php vendor/bin/phpunit                       # all suites
php vendor/bin/phpunit --filter ShipmentTest
composer lint                                # php -l over every plugin file
```

**Last green run: 238 tests, 1,952 assertions** (this increment; the suite was 232
tests before it). `vendor/` is present after a checkpoint restore; there is no
`composer` binary, so do not try to reinstall dependencies unless needed.

### Rebuilding the test runner on a bare Freebuff VM (no sudo)

`/tmp` is wiped on archive/restore, so this is often the first job. There is no
root; download a static PHP and extract the MariaDB debs into a prefix.

```bash
mkdir -p /tmp/wsp-tools /tmp/wsp-root /tmp/wsp-debs
cd /tmp/wsp-tools
curl -fsSL -o php.tar.gz https://dl.static-php.dev/static-php-cli/common/php-8.4.23-cli-linux-x86_64.tar.gz
tar -xzf php.tar.gz && chmod +x php

cd /tmp/wsp-debs
base=https://archive.ubuntu.com/ubuntu/pool/universe/m/mariadb
curl -fsSLO $base/mariadb-server-core_10.11.14-0ubuntu0.24.04.1_amd64.deb
curl -fsSLO $base/mariadb-client-core_10.11.14-0ubuntu0.24.04.1_amd64.deb
curl -fsSLO $base/mariadb-server-10.5_10.11.14-0ubuntu0.24.04.1_amd64.deb
curl -fsSLO https://archive.ubuntu.com/ubuntu/pool/main/libu/liburing/liburing2_2.5-1build1_amd64.deb
curl -fsSLO https://archive.ubuntu.com/ubuntu/pool/main/n/numactl/libnuma1_2.0.18-1ubuntu0.24.04.1_amd64.deb
for d in *.deb; do dpkg-deb -x "$d" /tmp/wsp-root; done

export LD_LIBRARY_PATH=/tmp/wsp-root/usr/lib/x86_64-linux-gnu
mkdir -p /tmp/wsp-tools/mysql-data
/tmp/wsp-root/usr/bin/mariadb-install-db --no-defaults --basedir=/tmp/wsp-root/usr \
  --datadir=/tmp/wsp-tools/mysql-data --auth-root-authentication-method=normal --skip-test-db
nohup /tmp/wsp-root/usr/sbin/mariadbd --no-defaults --basedir=/tmp/wsp-root/usr \
  --datadir=/tmp/wsp-tools/mysql-data --port=3307 --bind-address=127.0.0.1 \
  --socket=/tmp/wsp-tools/mysql.sock --pid-file=/tmp/wsp-tools/mysql.pid \
  --innodb-buffer-pool-size=64M >/tmp/wsp-tools/mysqld.log 2>&1 &
/tmp/wsp-root/usr/bin/mariadb --no-defaults --socket=/tmp/wsp-tools/mysql.sock -uroot \
  -e "CREATE DATABASE IF NOT EXISTS storekeeper_test"
```

Then run `/tmp/wsp-tools/php vendor/bin/phpunit`. The package names above are the
ones that exist in the Ubuntu 24.04 pool today (`mariadb-server-10.5`,
`mariadb-client-core`); a `404` means the version moved, so list the pool
directory and use the current filenames.

### Preview site

The preview stack also lives in `/tmp` and does not survive a restore. Full steps:
`docs/PREVIEW.md`. Short version: static PHP 8.3, WP-CLI, MariaDB on 3307, WordPress
multisite in `/tmp/wsp-preview/wordpress`, symlinked plugins, subsite
`ladies-corner` (blog 2), `wp storekeeper migrate --network`, seed with
`tools/preview/seed.php`, theme activated, `wp rewrite flush`, demo photos, then
`tools/preview/start.sh` on `0.0.0.0:3000` and `cloud_show_dev_server(3000)`.

URLs: `/ladies-corner/`, `/ladies-corner/shop/`,
`/ladies-corner/product/{slug}/`,
`/ladies-corner/wp-admin/admin.php?page=storekeeper`.

Known preview quirks: the first navigation to wp-admin often bounces home
(navigate twice); `*.style.dev` answers 403 to curl without the owner's session
and that is not a bug; a logged-in WP admin bar appears on the storefront; the
seed is not idempotent for locations; the preview uses the `log` notification
transport, so codes and messages appear in the PHP error log.

## 6. Verification status — what is actually proven

**Proven by the suite (238 tests):** money/phone/capabilities units; schema
migrations; pricing resolution; ledger invariants and 50-buyer concurrency; order
state machine, payments and holds; checkout/cart/shipping quotes; customers and
approvals; storefront view/account; OTP; notifications outbox and template
overrides; parcels (lifecycle, bulk, listing, slip data); POS counter sales and
sync (incl. rate limits and the pull-as-sync buffer); tenant isolation; platform
plans/billing/webhook.

**Not proven at all:**

- No real Paystack/Flutterwave/OPay charge, plan, subscription, refund or
  webhook delivery. The adapters are exercised against fixtures only.
- No live multisite provisioning; the provisioner has only been run against a
  fake.
- No browser or device test of `/pos/`, offline sync, the 429 path, or the
  storefront safety buffer.
- No browser test of the new Shipping, Parcels or Messages screens, including
  printing; the REST routes have service-level coverage but no HTTP test.
- No real email, SMS or WhatsApp delivery; the preview logs messages.
- No courier, printer, bank-transfer proof upload or custom-domain/SSL work.

The staff screens commit was verified by `php -l` on every changed PHP file,
`node --check` on `admin.js` and `screens.js`, and a full green suite. Nothing
was clicked through.

## 7. Deliberate limitations right now

- **OTP delivery is email only.** A customer with no address, or a mail transport
  that refuses, falls back to the PHP error log. WhatsApp/SMS is not built
  (Q-005, Q-006); a live store needs working mail and a From address (Q-017).
- **Notifications are email-only** at the provider level, though templates and
  the outbox are now editable in the back office. No WhatsApp/SMS adapter, no
  merchant alert *delivery* (alerts are read-only panes).
- **Staff users are created in WordPress**, not in the Store Keeper screen
  (D-021). Store roles limit capability, not data visibility.
- **Virtual accounts, refunds and payment reconciliation are deferred** (D-019).
- **Cart and checkout are server-rendered form posts, not REST** (D-028). Guests
  have no REST identity and a public write API needs rate limiting first.
- **The account page is read-only** apart from repeating an order; saved
  addresses come from checkout and cannot be edited there.
- **The storefront theme is PHP-templated, not a block theme** (D-024), and its
  interactivity is vanilla JS (D-025).
- **POS has no thermal printing and no barcode camera** (Q-021); receipts use the
  browser print dialog. Its rate limits and buffer policies are tested at the
  service layer only.
- **Platform charging is not built.** The console records invoices and the
  webhook settles them; it does not create a Paystack transaction, Plan or
  Subscription. Custom domains and SSL are not mapped (Q-019, Q-020).
- **No product sitemap or structured data**; OG/canonical only.
- **No imports/exports, no refunds screen, no dashboard charts.**
- **Screenshots in `artifacts/` are untracked** on purpose
  (`cart.png`, `checkout.png`, `order-received.png`).

## 8. Open questions that block or shape the next work

| # | Question | Why it matters now |
|---|---|---|
| Q-001b | OPay merchant ID + keys | OPay cannot go live; paste keys in Gateways when available. |
| Q-002 | Product name and domain | Blocks branding, theme name and transactional copy. |
| Q-003 | Plan prices and limits | The platform cannot charge without them (Q-020). |
| Q-004 | Pilot merchant(s) | The storefront gate needs a real merchant and their catalogue. |
| Q-005 | WhatsApp route (Cloud API direct or BSP) | Blocks the WhatsApp order desk and message delivery. |
| Q-006 | SMS provider | Blocks SMS codes and alerts. |
| Q-008 | Legal pages | No privacy/terms template on the storefront. |
| Q-012 | Guest checkout allowed? | Shipped as yes for retail; confirm before launch. |
| Q-013 | Launch delivery: pickup + one flat fee? | Checkout offers pickup, plus delivery only once methods exist. |
| Q-014 | Hold policy: 30 min gateway / 24 h transfer? | Implemented as the brief states; expiry cancels automatically. |
| Q-015 | Bank details for transfer | Stored as settings; still needed for live copy. |
| Q-016 | Code channel | Answered as email for now (D-036). |
| Q-017 | Mail From address / transport | Needed before a real code or receipt is sent. |
| Q-018 | Nigerian LGA dataset + real zone fees | The shipping screen takes text areas; no LGA picker yet. |
| Q-019 | Custom domains / SSL | Not built; platform tenancy works on subsites only. |
| Q-020 | Platform billing account | Charge/Plan/Subscription creation not built. |
| Q-021 | POS hardware | Decides whether thermal printing and a barcode camera are needed. |

`docs/INTEGRATIONS.md` lists every credential still needed and where it plugs in.

## 9. What is left — recommended order

1. **Prove the money path.** A test-mode Paystack charge end to end (payment link →
   redirect → webhook on a public URL → `payments` row → order paid), then
   Flutterwave and OPay if keys arrive. Nothing else matters as much.
2. **Run the POS in a real browser and on an Android tablet**: install `/pos/`,
   register a device, sell, cut the network, sell offline, re-connect, push,
   pull, and hit the 429 and the stale-buffer view. Fix what the run reveals.
3. **Platform billing**: create the Paystack transaction/Plan/Subscription behind
   `POST /platform/invoices` (Q-003, Q-020), then custom domains/SSL (Q-019).
4. **WhatsApp/SMS adapters** for OTP and order messages once Q-005/Q-006 are
   answered; the `Channel` seam and the outbox already exist.
5. **Remaining storefront gaps**: edit a saved address, bank-transfer proof
   upload, product sitemap, WhatsApp order desk.
6. **Operational polish**: merchant alert delivery, dispatch-of-messages retry
   button (today a failed message is retried by the next dispatch), revenue and
   health panes on the platform console.
7. **Live provisioning**: run the WordPress provisioner against a throwaway
   subsite, then a real application, and watch `platform_jobs` steps.

Do not start with UI polish: the user has explicitly deferred front-end design.

## 10. How the work is run

- Phase-sized increments. Each ends with code, tests, docs (`DECISIONS.md` for
  deviations, `OPEN_QUESTIONS.md` for owner questions, the topic doc and this
  handover), one commit with § references, push to `main`.
- Do not invent product decisions. If the brief is silent, choose the smallest
  option that unblocks work, record a `D-0xx`, continue. If it needs the owner
  (money, provider, legal), add `Q-0xx` and use a placeholder.
- Never fake a feature: no dead buttons, no stub that pretends to work. An honest
  absence is preferred.
- Report honestly what was and was not verified. "Not clicked through" is the
  correct ending when no browser ran.

## 11. Document index

| File | Read it for |
|---|---|
| `docs/BRIEF.md` | Requirements, phases, E2E tests, data model. |
| `docs/DECISIONS.md` | Every deviation and why (D-001…D-043). Read before structural changes. |
| `docs/OPEN_QUESTIONS.md` | What is waiting on the owner, and the placeholder in code. |
| `docs/API.md` | Shipped endpoints, error codes, shapes. |
| `docs/ADMIN.md` | Every back-office screen, capability, and the parcels/shipping/messages workflows. |
| `docs/DESIGN.md` | Back-office shell, tokens, CSS rules, printing. |
| `docs/PREVIEW.md` | Rebuilding the preview stack from zero. |
| `docs/STOREFRONT.md` | Storefront routes, caching, swatches, theme install. |
| `docs/POS.md` | `/pos/`, devices, PINs, counter sales/returns, sync protocol and its limits. |
| `docs/PLATFORM.md` | Plans and enforcement, provisioning, platform billing, console, impersonation. |
| `docs/INTEGRATIONS.md` | Credentials still needed and where they go. |
| `docs/PHASE_*_REPORT.md` | What each phase delivered and what it skipped. |
| `docs/ENGINEERING_ASSESSMENT.md` | Historical snapshot from the first engine review; some lines are stale (e.g. "no rate limiting" was true then). |
