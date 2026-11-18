# Handover

Written 2026-11-16 for whoever picks this up next (human or model). Read this
first, then `docs/BRIEF.md` for requirements and `docs/DECISIONS.md` for every
choice that deviates from it.

## 1. What this is

**Store Keeper** is a hosted commerce platform for Nigerian wholesalers and
retailers, built on WordPress multisite. Each merchant is a subsite with its own
store tables, staff, catalog, storefront, WhatsApp order desk and (later) POS. The
engine is plain PHP with no knowledge of HTTP or HTML; screens call a REST API; the
API calls the engine; only the engine writes to tables.

- Working name: **Store Keeper** (final name is Q-002, all names come from
  `plugins/storekeeper-commerce/src/Core/Brand.php`).
- Brief: `docs/BRIEF.md` (2,095 lines, § references used everywhere).
- Repo: `promiseabanks-cloud/store-keeper`, branch `main`, everything pushed.
- Workspace: `/workspace/chats/bd9d595656ccf5a40359f69f` (Freebuff cloud chat).

## 2. Where the build stands

The brief schedules six phases (§22.2–§22.7). Implemented so far:

| Brief phase | State | Notes |
|---|---|---|
| §22.1 Phase 0 (discovery) | Not recorded here | Owner-facing questions live in `OPEN_QUESTIONS.md`. |
| §22.2 Phase 1 — Foundation | **Done** | Core constants, money, phone, DB abstraction, schema + migrations, capabilities, REST conventions, WP-CLI, CI. |
| §22.3 Phase 2 — Back office | **Done** | Catalog, pricing resolution, stock ledger with reservations, orders, customers, back-office app shell and its read/write APIs. |
| §22.4 Phase 3 — Orders, payments, notifications | **Payments, hold expiry, notifications, shipping and parcels done; the rest not** | Paystack, Flutterwave and OPay adapters, payment links and signed webhooks are shipped (OPay cannot go live without the merchant ID, Q-001b; no real charge has ever been made). Hold expiry runs hourly and via `wp storekeeper orders expire-holds`. Notifications: templates, outbox in `message_log`, order buyer/staff emails, shipment emails, hourly dispatch and `wp storekeeper messages dispatch`. Shipping: zones, areas and all five method types, quoted from the cart at checkout (D-037; real fees/LGA dataset are Q-013/Q-018). Parcels: multiple per order, per-parcel stock commit, dispatch details, POD collection, returns with optional restock, events timeline, bulk status, public `/track/{number}/?t=` page (D-038). **Not built:** WhatsApp/SMS adapters, message templates screen, message log screen, merchant alert delivery, courier API adapters (§13.3 v2), dispatch-sheet printing. |
| §22.5 Phase 4 — Storefront | **Mostly done (catalogue, cart, checkout, sign-in, sign-up, account)** | Theme, `/shop/`, categories, search, product page with swatches and group pricing, block + shortcode, OG/canonical. Cart (`/cart/`), guest/retail checkout (`/checkout/`), phone + OTP sign-in (`/sign-in/`, `skf_session`), wholesale self-registration (`/register/`, pending approval honoured) and the account page (`/account/`: order history, repeat order, saved addresses) are shipped; a signed-in wholesale customer checks out at group prices and the order records `customer_id` and the group. Codes are emailed to the customer's address (D-036). The order and tracking pages now show the parcel timeline. **Not built:** WhatsApp/SMS delivery, editing a saved address from the account page, bank-transfer proof upload, product sitemap. |
| §22.6 Phase 5 — POS + offline sync | **Engine and app done; not browser-tested** | `/pos/` installable page, device tokens, cashier PINs, counter sales/returns/cash-up, parked sales, pull/push sync with batch and per-operation idempotency, oversell alerts and device-stale alerts (D-039, `docs/POS.md`). Storefront safety buffer, thermal printers and rate limiting are not built. |
| §22.7 Phase 6 — Platform layer | **Engine, console and webhook done; provisioning not run live** | Plans + PlanGate + usage, applications, tenant provisioning with a step log, invoices, dunning ladder, verified Paystack webhook, owner console with audited impersonation (D-040, `docs/PLATFORM.md`). Paystack charge/subscription creation, custom domains/SSL and the revenue/health panes are not built. |

**Numbering warning.** Commit messages and `docs/PHASE_*_REPORT.md` use an internal
count that is one behind the brief: internal "Phase 2" = brief §22.3, internal
"Phase 3" = brief §22.4 payments, internal "Phase 4" = the back-office remainder
plus storefront, which the brief splits between §22.3 and §22.5. When in doubt,
quote the brief section number.

Commit trail (oldest first relevant): `a1129bc` core → `71f82b5` WP integration →
`312ea3e` engine → `ef58166` back-office shell + OPay + preview → `c5d38de` orders
→ `1bf2be8` gateways → `22ed483` customers/prices/locations/staff/alerts →
`2b2e9e8` back-office redesign → `63acd0a` storefront → `e8d87df` handover docs
→ cart, checkout and hold expiry → notifications outbox → storefront sign-in → wholesale sign-up and account → email OTP delivery → shipping zones/methods and parcels (this increment).

## 3. Intent — how the work is being run

- Build in phase-sized increments. Each increment ends with: code, tests, docs
  updated (`DECISIONS.md` for deviations, `OPEN_QUESTIONS.md` for owner
  questions, a phase report or topic doc), one commit with § references, push to
  `main`.
- The user reviews visually through a browser preview and gives short, direct
  direction ("I don't want the basic WordPress feel"). They expect visible
  progress and honest reporting of what was and was not checked.
- Do not invent product decisions. If the brief is silent, choose the smallest
  option that unblocks work, record it as a `D-0xx`, and continue. If a decision
  needs the owner (money, provider, legal), add a `Q-0xx` and use a placeholder.
- Never fake a feature: no dead buttons, no stub that pretends to work. An honest
  absence (e.g. no "add to cart" until the cart exists) is preferred.

## 4. Architecture map

```
plugins/storekeeper-commerce/
  storekeeper-commerce.php      bootstrap, activation, REST registration, WP-CLI
  src/Core/                     Brand, Money, Phone, Uuid, Clock, Container,
                                Connection (Wpdb + Pdo), Installer, SchemaMigrator,
                                Idempotency, Audit, Hooks, Crypto
  src/Catalog/                  products, options, variants, media, categories
  src/Pricing/                  PricingService::resolve() is the only price source
  src/Inventory/                stock ledger, reservations, receipts, transfers,
                                counts, locations
  src/Customers/                customers, groups, approvals, totals
  src/Orders/                   OrderService: create, pay, hand over, cancel,
                                refresh statuses
  src/Payments/                 GatewayAdapter + Paystack/Flutterwave/OPay,
                                PaymentService, PaymentRepository, settings
  src/Shipping/                 zones, areas, method quotes (§13.3)
  src/Orders/                   OrderService + ShipmentService/Repository
  src/Pos/                      devices, PINs, counter sales, change feed, sync (§15)
plugins/storekeeper-platform/src/
  Plans/                        plans, PlanGate limits/features, usage counters
  Applications/ Tenants/        application inbox and tenant records
  Provisioning/                 step log + WordPress provisioner
  Billing/                      invoices, dunning, Paystack event handling
  Console/ Http/                owner console, platform webhook
  src/Rest/                     thin controllers; no business logic
  src/Admin/                    wp-admin menu, assets, body class
  src/Storefront/               storefront read model, routes, cache, block
  src/Alerts/ src/Reports/ src/Cli/
  migrations/store/*.php        versioned schema (0001…0006), network/0001
  templates/storefront/         shop, product, not-found + parts
  assets/admin/                 back-office app shell (CSS + React-in-wp.element)
  assets/storefront/            storefront.css, product.js
  blocks/product-grid/          block.json + plain-JS editor UI
  tests/                        Unit + Integration against real MariaDB
plugins/storekeeper-mu-loader/  load order + environment guards
plugins/storekeeper-platform/   Phase 6 shell (empty)
theme/storekeeper-storefront/   PHP-templated theme with block support (D-024)
tools/preview/                  WP preview stack scripts + seed + demo photos
docs/                           BRIEF, DECISIONS, OPEN_QUESTIONS, API, PREVIEW,
                                DESIGN, STOREFRONT, PHASE_*_REPORT
```

Read model vs engine: `CatalogView` (storefront) is allowed to read tables with
`Connection`, but it never writes. Everything that changes state goes through a
service.

## 5. Invariants and traps (read before editing)

1. **`declare(strict_types=1);` everywhere** in `src/` and `tests/`. `$wpdb` hands
   back numeric columns as strings — cast before arithmetic.
2. **SQL placeholders are `?`**, even though production is `$wpdb`.
   `WpdbConnection::prepare()` rewrites `?` into `%d`/`%s` itself. Writing `%d`
   in a query works only under `$wpdb` and breaks the PDO test driver (this bug
   was shipped once and caught by tests). `sprintf` is only for table names.
3. **Only services write.** Stock changes only through `InventoryService`
   movements; prices only through `PricingService::setPrice`; orders only through
   `OrderService`.
4. **Prices always resolve through `PricingService::resolve()`** (special price →
   group list tier → base list tier → derived pack → `PriceNotFound`). Do not
   compute a price anywhere else. `PricingRepository::displayAmounts()` exists
   only for product cards and mirrors that order.
5. **Ledger locking order:** lock `stock_levels` row (`FOR UPDATE`) → insert
   movement → apply delta, all in one transaction; hooks fire after commit (D-006,
   D-012).
6. **Reservations:** each line reserves from one location (first sellable with
   stock, D-005). `on_hand - reserved` is availability; storefront availability
   only sums locations with `sellable_online = 1 AND is_active = 1`.
7. **Hooks** are `storekeeper/{name}` (`Brand::hook()`), dispatched through
   `Core\Hooks\Hooks` (`WpHooks` in production, `InMemoryHooks` in tests).
8. **Storefront caching:** guest grids only, transient keyed by blog + price list
   + category + limit; bumped by `catalog/product_created`, `stock/*`,
   `order/*` via `Cache::flush()`. Signed-in customers bypass the cache entirely.
   If you add a new state change that affects the shop, add its hook to
   `Storefront::register()`.
9. **Storefront routes are rewrite rules** (`sk_view`, `sk_product`,
   `sk_category`, `sk_page`, `sk_order`). After deploying, `wp rewrite flush` is
   required once (activation already flushes). `redirect_canonical` is disabled for
   these routes.
10. **The storefront theme must be activated** and must find the plugin active;
    `theme/storekeeper-storefront` calls the plugin's `Storefront::view()`.
11. **wp-admin chrome is hidden only on the Store Keeper screen.** Everything is
    scoped under `body.storekeeper-app` / `.sk-root`; primary buttons need
    `body.storekeeper-app .sk-root .button.button-primary` to beat WordPress's
    own rules. `html.wp-toolbar:has(body.storekeeper-app)` removes the 32px
    toolbar offset.
12. **Block render callbacks must capture their dependencies explicitly** — a
    static method without the `$view` parameter silently got `null` and fataled
    (fixed in `Blocks::loadGrid`). Keep the callback signature `(array $attributes)`.
13. **`wp eval-file` evaluates the file**, so scripts under `tools/preview/` must
    not start with `declare(strict_types=1)`.
14. **`wp search-replace` is required whenever the preview hostname changes** —
    `SK_PREVIEW_HOST`, `DOMAIN_CURRENT_SITE` in `wp-config.php`, then the DB. See
    `docs/PREVIEW.md`.
15. **No product decisions in code**: gateway credentials, plan prices, product
    name, provider choices are placeholders or settings, never hard-coded values.
16. **The cart stores choices, not money.** `CartService::summary()` re-resolves
    every line through `PricingService` and reads availability from the ledger on
    every request; never add a `price_kobo` column to `cart_items` and never trust
    a posted variant price.
17. **POS writes go through the same services.** A counter sale is an `OrderService` order with `handed_over_now`; it must never insert an order or a movement itself. `client_uuid` is the order idempotency key and `batch_uuid` stores the push result — never drop either when adding an operation. Offline prices are honoured by design (D-039); do not "correct" them to the current price.
18. **Platform tables use `networkPrefix()`.** The platform plugin runs network-wide, so reading a store table from it is a bug; `PlanGate` takes the count from the caller instead.
19. **Checkout composes, it does not compute.** `CheckoutService` validates the
    shopper and picks the shipping fee, then calls `OrderService::create()` — which
    re-prices and reserves in one transaction. Guest orders are deliberately not
    attached to an existing customer record (D-027).

## 6. Running things

### Tests

Needs PHP 8.2+ (repo has no local PHP; the preview stack provides one) and a real
MariaDB on `127.0.0.1:3307`, database `storekeeper_test` (D-004; SQLite is not
supported).

```bash
php vendor/bin/phpunit                       # all suites
php vendor/bin/phpunit --filter StorefrontTest
composer lint                                # php -l over every plugin file
```

Last green run: **226 tests, 1,749 assertions** (POS, sync and platform included).

### Preview site

The preview stack lives in `/tmp`, **which is not checkpointed**: it disappears when
the runner moves or restarts. At the time of writing it is gone (PHP, MariaDB,
WordPress and the seeded subsite), so the first job in a fresh session is usually a
rebuild. Full steps: `docs/PREVIEW.md`. Short version:

1. Download PHP 8.3 (static build) into `/tmp/wsp-tools/php`, WP-CLI into
   `/tmp/wsp-tools/wp-cli.phar`, MariaDB 11.4 into `/tmp/wsp-tools/`.
2. Start MariaDB on 127.0.0.1:3307 with socket `/tmp/wsp-tools/mysql.sock`.
3. `wp core download` into `/tmp/wsp-preview/wordpress`, write `wp-config.php`
   (multisite, `SK_PREVIEW_HOST`, `WP_HOME`/`WP_SITEURL` from `HTTP_HOST`,
   `WP_DEBUG_LOG`), install, enable multisite, symlink the plugins, network
   activate, create subsite `ladies-corner` (blog 2), `wp storekeeper migrate
   --network`, seed with `SK_SEED_BLOG=2 … eval-file tools/preview/seed.php`.
4. Symlink and activate the theme, `wp rewrite flush`, then
   `wp eval-file tools/preview/demo-photos.php` for placeholder product photos.
5. `tools/preview/start.sh` serves `0.0.0.0:3000`; show it with
   `cloud_show_dev_server(3000)`.

URLs: `/ladies-corner/` (home), `/ladies-corner/shop/`,
`/ladies-corner/product/{slug}/`, `/ladies-corner/product-category/{slug}/`,
`/ladies-corner/wp-admin/admin.php?page=storekeeper` (back office).

Known preview quirks: the first navigation to wp-admin often bounces to home
(navigate twice); `*.style.dev` answers 403 to curl without the owner's session and
that is not a bug; a logged-in WP admin bar appears on the storefront (real
shoppers are not WP users); the seed is not idempotent for locations.

## 7. Deliberate limitations right now

- **OTP delivery is email only.** `EmailOtpSender` emails the code to the address
  on the customer's record; a customer with no address, or a mail transport that
  refuses, falls back to the PHP error log. A live store therefore needs working
  mail and a From address (Q-017) before a shopper receives a code. WhatsApp/SMS
  is not built (Q-005, Q-006); Q-016 is answered as email for now.
- **No storefront registration or account area.** Staff create customers and the
  approval queue exists, but a visitor cannot apply as a wholesaler from the shop,
  browse past orders or manage addresses.
- **Cart and checkout are server-rendered form posts, not REST** (D-028). Guests
  have no REST identity, and a public write API needs rate limiting first.
- **Staff screen is read-only** (D-021); people are created on the WP Users screen.
- **Virtual accounts, refunds and reconciliation are deferred** (D-019).
- **Sign-up and sign-in email the code.** Registration now asks for an email address, which is also where order confirmations go. The preview host runs the `log` transport, so it shows the code with the two switches in `PREVIEW.md` instead of sending mail.
- **The account page is read-only** apart from repeating an order: saved addresses come from checkout and cannot be edited there yet.
- **Notifications are email-only.** `message_log` queues order confirmations and staff alerts and dispatches them hourly, but there is no WhatsApp/SMS adapter (Q-005/Q-006) and no screen to edit templates or read the log; both are API/WP-CLI only. A preview host uses the `log` transport because it has no mail server.
- **Back-office alerts** are read-only panes; there is no notification delivery for them.
- **The storefront theme is PHP-templated, not a block theme** (D-024), and its
  interactivity is vanilla JS, not the Interactivity API (D-025).
- **POS is not browser-tested and has no rate limiting.** Device tokens are the only gate; a leaked token can push sales until it is revoked. The storefront does not subtract `offline_buffer_units` yet, receipts use the browser print dialog, and there is no barcode-camera support (Q-021).
- **Platform charging is not built.** The console records invoices and the webhook settles them, but it does not create a Paystack transaction, Plan or Subscription. Custom domains and SSL are not mapped (Q-019, Q-020).
- **No product sitemap or structured data**; OG/canonical only.
- **Nothing has been tested against a real gateway, a real phone, a real courier, a real device or a live Paystack platform account.**

## 8. Open questions that matter next

| # | Question | Why it matters now |
|---|---|---|
| Q-001b | OPay merchant ID + keys | OPay cannot go live; paste keys in Payment gateways once available. |
| Q-002 | Product name and domain | Blocks branding, theme name, transactional copy. |
| Q-004 | Pilot merchant(s) | The storefront gate ("pilot's website live on the same stock") needs one. |
| Q-005 | WhatsApp route (Cloud API direct or BSP) | Blocks notifications and the WhatsApp order desk. |
| Q-008 | Legal pages | The storefront has no privacy/terms template yet. |
| Q-012 | Guest checkout allowed? | Shipped as yes for retail; confirm before launch. |
| Q-013 | Launch delivery: pickup + one flat fee? | Checkout offers pickup, plus delivery only when `storekeeper_flat_shipping_kobo` is set. |
| Q-014 | Hold policy: 30 min gateway / 24 h transfer? | Implemented as the brief states; expiry now cancels automatically. |

## 9. Recommended next step

All six brief phases now have their engine: the merchant core, the customer path,
shipping/parcels, the POS with offline sync, and the platform layer. What remains
is integration proof and the screens around the engines.

Highest value next:

- **Exercise the POS in a real browser and on an Android tablet**, including a
  deliberate network cut. The engine is covered by 16 tests; the app is not.
- **Rate-limit the device endpoints** and add the storefront safety buffer
  (`offline_buffer_units`) so an offline device cannot oversell silently.
- **Staff screens for parcels and shipping settings** on the existing REST routes
  (to-pack, dispatch sheet, packing slip, §14.2), plus the message log/templates
  screen (§12.4).
- **Platform billing charge creation** against the Owner's Paystack account, then
  custom domains and SSL (Q-019, Q-020).
- **Owner decisions still open:** plan prices (Q-003), real zone/fee data
  (Q-013), the Nigerian LGA dataset (Q-018), WhatsApp/SMS (Q-005/Q-006), mail
  From address (Q-017), OPay merchant ID (Q-001b), bank details (Q-015), POS
  hardware (Q-021).

`docs/INTEGRATIONS.md` lists every credential still needed and where it plugs in.

## 10. Document index

| File | Read it for |
|---|---|
| `docs/BRIEF.md` | Requirements, phases, E2E tests, data model. |
| `docs/DECISIONS.md` | Every deviation and why (D-001…D-038). Read before changing anything structural. |
| `docs/OPEN_QUESTIONS.md` | What is waiting on the owner, and the placeholder in code. |
| `docs/API.md` | Shipped endpoints, error codes, shapes. |
| `docs/PREVIEW.md` | Rebuilding the preview stack from zero. |
| `docs/DESIGN.md` | Back-office shell, tokens, CSS rules. |
| `docs/STOREFRONT.md` | Storefront routes, caching, swatches, theme install. |
| `docs/POS.md` | `/pos/`, devices, PINs, counter sales/returns, sync protocol and its limits. |
| `docs/PLATFORM.md` | Plans and enforcement, provisioning, platform billing, console, impersonation. |
| `docs/PHASE_*_REPORT.md` | What each phase delivered and what it skipped. |
