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
| §22.4 Phase 3 — Orders, payments, notifications | **Payments, hold expiry and email notifications done; the rest not** | Paystack, Flutterwave and OPay adapters, payment links and signed webhooks are shipped (OPay cannot go live without the merchant ID, Q-001b; no real charge has ever been made). Hold expiry runs hourly and via `wp storekeeper orders expire-holds`. Notifications: templates, outbox in `message_log`, order buyer/staff emails, hourly dispatch and `wp storekeeper messages dispatch`. **Not built:** shipping zones/methods, shipments and public tracking, status bulk actions, WhatsApp/SMS adapters, message templates screen, message log screen, merchant alert delivery. |
| §22.5 Phase 4 — Storefront | **Partially done (catalogue + cart + checkout + sign-in)** | Theme, `/shop/`, categories, search, product page with swatches and group pricing, block + shortcode, OG/canonical. Cart (`/cart/`), guest/retail checkout (`/checkout/`), phone + OTP sign-in (`/sign-in/`, `skf_session`) and the token-protected order page are shipped; a signed-in wholesale customer checks out at group prices and the order records `customer_id` and the group. **Not built:** a real OTP delivery provider (Q-016), account order history, address book screen, tracking page styling, wholesale registration, product sitemap. |
| §22.6 Phase 5 — POS + offline sync | **Not started** | Only the tables exist. |
| §22.7 Phase 6 — Platform layer | **Not started** | `plugins/storekeeper-platform` is a plugin header and nothing else. |

**Numbering warning.** Commit messages and `docs/PHASE_*_REPORT.md` use an internal
count that is one behind the brief: internal "Phase 2" = brief §22.3, internal
"Phase 3" = brief §22.4 payments, internal "Phase 4" = the back-office remainder
plus storefront, which the brief splits between §22.3 and §22.5. When in doubt,
quote the brief section number.

Commit trail (oldest first relevant): `a1129bc` core → `71f82b5` WP integration →
`312ea3e` engine → `ef58166` back-office shell + OPay + preview → `c5d38de` orders
→ `1bf2be8` gateways → `22ed483` customers/prices/locations/staff/alerts →
`2b2e9e8` back-office redesign → `63acd0a` storefront → `e8d87df` handover docs
→ cart, checkout and hold expiry (this increment).

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
17. **Checkout composes, it does not compute.** `CheckoutService` validates the
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

Last green run: **120 tests, 1,326 assertions** at commit `63acd0a`.

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

- **No OTP delivery provider.** Sign-in itself works and is tested end to end, but
  `OtpSender` has only the log implementation: a code reaches the PHP error log and,
  behind two preview switches, the preview screen. Shoppers cannot receive a code
  until Q-016 (WhatsApp vs SMS, sender number) is answered and one adapter is
  written.
- **No storefront registration or account area.** Staff create customers and the
  approval queue exists, but a visitor cannot apply as a wholesaler from the shop,
  browse past orders or manage addresses.
- **Cart and checkout are server-rendered form posts, not REST** (D-028). Guests
  have no REST identity, and a public write API needs rate limiting first.
- **Staff screen is read-only** (D-021); people are created on the WP Users screen.
- **Virtual accounts, refunds and reconciliation are deferred** (D-019).
- **Notifications are email-only.** `message_log` queues order confirmations and staff alerts and dispatches them hourly, but there is no WhatsApp/SMS adapter (Q-005/Q-006/Q-016) and no screen to edit templates or read the log; both are API/WP-CLI only. A preview host uses the `log` transport because it has no mail server.
- **Back-office alerts** are read-only panes; there is no notification delivery for them.
- **The storefront theme is PHP-templated, not a block theme** (D-024), and its
  interactivity is vanilla JS, not the Interactivity API (D-025).
- **No product sitemap or structured data**; OG/canonical only.
- **Nothing has been tested against a real gateway, a real phone or a real courier.**

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

Email notifications shipped, so an order now confirms itself without a provider.
Next is the rest of §22.5 (account, addresses, wholesale registration), then
the WhatsApp/SMS adapters once Q-016 is answered, then shipments and tracking.

Suggested shape, consistent with what exists:

- `{p}customer_sessions` and `{p}otp_codes` already exist; build the service that
  issues a 6-digit code, hashes it, enforces 10-minute expiry / 5 attempts / 5 per
  phone per hour / 20 per IP per hour, and issues the 32-byte session cookie
  (90-day sliding expiry, "sign out of all devices").
- OTP delivery needs `src/Notifications` and the adapters from §22.4 (Q-005/Q-006).
  Until then, the session seam is `Storefront\CustomerContext`; set it from the
  session cookie in `Storefront::register()`.
- Once a customer can sign in: merge the guest cart into their active cart on
  login, wire the address book (`CustomerService::addAddress` exists), order
  history (`OrderRepository::list` filtered by customer), and wholesale
  registration with the approval queue that already exists.
- Then the public tracking page (`OrderService::findByPublicToken` already returns
  the order) and the notifications that go with status changes.

After that, §22.4's remaining fulfilment work: shipping zones/methods, shipments,
bulk status actions, and the message log.

## 10. Document index

| File | Read it for |
|---|---|
| `docs/BRIEF.md` | Requirements, phases, E2E tests, data model. |
| `docs/DECISIONS.md` | Every deviation and why (D-001…D-025). Read before changing anything structural. |
| `docs/OPEN_QUESTIONS.md` | What is waiting on the owner, and the placeholder in code. |
| `docs/API.md` | Shipped endpoints, error codes, shapes. |
| `docs/PREVIEW.md` | Rebuilding the preview stack from zero. |
| `docs/DESIGN.md` | Back-office shell, tokens, CSS rules. |
| `docs/STOREFRONT.md` | Storefront routes, caching, swatches, theme install. |
| `docs/PHASE_*_REPORT.md` | What each phase delivered and what it skipped. |
