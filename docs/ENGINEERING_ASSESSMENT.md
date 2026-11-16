# Engineering Assessment — Blueprint vs Repository

**Date:** 2026-11-16
**Assessed commit:** `e8d87df` (branch `main`, working tree clean)
**Method:** direct repository inspection (source, migrations, tests, docs) plus the
supplied `STORE_KEEPER_CONSOLIDATED_ENGINEERING_BLUEPRINT.md` and `docs/HANDOVER.md`.
**No source files were modified to produce this report.**

Status labels used below: **Verified** (read in source), **Historical** (reported by a
previous run, not re-run now), **Assumed** (reasonable inference, not checked),
**Unverified** (could not be checked in this environment).

> **Verification limitation.** This environment currently has **no PHP binary and no
> database** (`/tmp` was reset; `command -v php` → not found). The test suite was
> **not executed for this assessment**. The last recorded green run was
> `120 tests / 1,326 assertions` at `63acd0a` (Historical). Everything below is
> static inspection. Rebuild the toolchain (`docs/PREVIEW.md`) and run
> `vendor/bin/phpunit` before treating any "implemented" item as proven.

---

## 1. Current verified implementation status

| Area | Status | Evidence |
|---|---|---|
| Core primitives (Brand, Money, Phone, Uuid, Clock, Crypto) | Implemented | `src/Core/`; `Brand.php` is the single source of names (§7.2) |
| DB abstraction + migrations | Implemented | `Core/Database/{WpdbConnection,PdoConnection}`, `Installer`, `SchemaMigrator`; 7 store migrations + 1 network migration |
| Capabilities and roles | Implemented | `Core/Capabilities.php` (24 capabilities, 6 roles) |
| REST conventions | Implemented, under-used | `Rest/Api.php`: capability auth, `no-store` header, idempotency helper used by 2 controllers only (`CatalogController:113`, `InventoryController:99`) |
| Catalog | Implemented | `Catalog/CatalogService.php`, `CatalogRepository.php`; product/variant/option/media/category |
| Pricing | Implemented | `Pricing/PricingService::resolve()` with special → group tier → base tier → derived pack; wholesaler-below-tier rule at `PricingService.php:78` and tested at `PricingResolutionTest.php:74` |
| Inventory ledger | Implemented | `Inventory/InventoryService.php`; opening, receive, adjust, transfer, count, reserve/release, `stock/moved`, ledger verify CLI |
| Customers and groups | Implemented | `Customers/CustomerService.php`: create, findOrCreate (`:122`), update, approve (`:176`), reject, changeGroup, setSpecialPrice, addAddress (`:290`), addresses, findByPhone |
| Orders | Implemented | `Order/OrderService.php`: create (`:51`), cancel, handover, recordPayment, refreshStatuses; item snapshots in `order_items` (product name, SKU, unit price, price source) |
| Payments | Implemented for card/transfer links | `Payments/PaymentService::initialize()` (`:51`), `Gateway/{Paystack,Flutterwave,Opay}Gateway`, signed webhooks, replay guard via `UNIQUE(method, gateway_reference)` |
| Back office UI | Implemented | `src/Admin/Menu.php` + `assets/admin/admin.js|css`; Overview, Products, Orders, Customers, Prices, Locations, Staff, Gateways |
| Storefront | Implemented first slice | `Storefront/{Storefront,CatalogView,Blocks,CustomerContext,Cache}.php`, `templates/storefront/`, `theme/storekeeper-storefront/` |
| Alerts | Partially | `Alerts/AlertService.php` raises and lists; no delivery, no UI actions beyond resolve |
| Reports | Minimal | Only `Reports/OverviewService::overview()`; no `/reports/*` endpoints or CSV |
| Cart / checkout | **Not implemented** | No `src/Cart`, no `src/Checkout`, no cart table, no cart route, no cart template (`grep -i cart src/` finds only comments) |
| Customer auth / sessions / OTP | **Not implemented** | Tables exist (`0002_pricing_customers.php`: `addresses`, `customer_sessions`, `otp_codes`); no service or controller references them |
| Shipping zones / methods / shipments / tracking | **Not implemented** | Tables exist (`0004_orders_fulfilment.php:112-160`); no `src/Shipping`, no `src/Fulfilment` |
| Notifications (templates, log, WhatsApp/SMS) | **Not implemented** | `message_templates`, `message_log` exist in `0005_system.php`; no `src/Notifications` |
| POS / devices / sync | **Not implemented** | `devices`, `sync_batches` exist in `0005_system.php`; no code |
| Refunds / virtual accounts / reconciliation | **Not implemented** | `refunds` table exists; `GatewayAdapter::supports()` returns false for both (D-019) |
| Platform layer | **Not implemented** | `plugins/storekeeper-platform/storekeeper-platform.php` is a header only; network tables exist in `migrations/network/0001_platform.php` |
| Hold expiry | **Partially** | `OrderService::holdExpiry()` (`:697`) stores `hold_expires_at`; nothing ever reads it — no job, no CLI command (`grep hold_expires_at src/` returns only the write path) |
| CI | Present, unverified | `.github/workflows/ci.yml` runs PHP 8.3 + MariaDB 11.4 engine tests; current pass status not checked |

Test inventory: 14 integration + 4 unit files, **114 `test` methods** (some data
providers explain the 120 executed count). Coverage is engine-heavy; there are no
REST contract tests, no browser tests, no cart tests.

---

## 2. Existing architecture and important dependencies

Verified structure (do not replace):

```
plugins/storekeeper-commerce/storekeeper-commerce.php   bootstrap + activation + REST registration + WP-CLI
  src/Core/        Brand, Money, Phone, Uuid, Clock, Crypto, Container,
                   Database/{Connection,WpdbConnection,PdoConnection},
                   Installer, SchemaMigrator, Idempotency, Audit, Hooks
  src/Catalog/     CatalogService, CatalogRepository
  src/Pricing/     PricingService, PricingRepository, ResolvedPrice, PriceSource
  src/Inventory/   InventoryService, InventoryRepository, MovementType
  src/Customers/   CustomerService, CustomerRepository
  src/Orders/      OrderService, OrderRepository, status value objects
  src/Payments/    PaymentService, PaymentRepository, Gateway/{Adapter,Abstract,Paystack,Flutterwave,Opay}, GatewaySettings
  src/Rest/        Api + 9 controllers
  src/Admin/       Menu.php
  src/Storefront/  Storefront (routes), CatalogView, Blocks, CustomerContext, Cache, Format
  src/Alerts/ src/Reports/ src/Cli/
  migrations/store/{0001..0006}.php, migrations/network/0001_platform.php
  templates/storefront/, assets/{admin,storefront}/, blocks/product-grid/
theme/storekeeper-storefront/
tools/preview/  (seed, demo photos, router, start.sh)
docs/
```

Key dependency facts:

- `Plugin::container()` (`src/Core/Plugin.php`) builds every service lazily; new
  services must be registered there and in `storekeeper-commerce.php`.
- `Storefront::view()` resolves `CatalogView` from that container; storefront
  templates call `get_header()`/`get_footer()` from the theme.
- `Rest\Api` is the only shared REST gate: `requireCapability()`, `staffActor()`,
  `idempotent()`. `idempotent()` hard-codes the actor key as
  `'staff:' . get_current_user_id()` — **unusable for customer requests**.
- `OrderService::create()` already accepts `channel`, `customer_id` (not a raw
  customer payload), `lines`, `payment_method`, `shipping_kobo`,
  `shipping_method_id`, `shipping_address`, `hold_minutes`, `idempotency_key`,
  `draft`, `handed_over_now`. It reserves stock per line inside one transaction and
  snapshots items.
- `OrderService::resolveCustomer()` (`:632`) returns `input['customer']` **verbatim**
  when supplied. That path must never receive browser data.
- `OrderService::resolveGroup()` (`:655`) trusts `customer_group_id` if present.
  Storefront code must not pass it.
- `PaymentService::initialize($orderUuid, $slug, Actor)` creates a `pending`
  payment row, calls the adapter, and returns the redirect URL. Reusable by
  checkout.
- `Actor::customer(int $customerId)` exists (`Core/Audit/Actor.php:51`), and
  `orders.created_by_type` already allows `customer`.
- Cache invalidation for the shop listens to `order/created` and `order/*`
  (`Storefront::register()`), so a checkout that creates an order will correctly
  flush guest grids.

---

## 3. Comparison against the consolidated blueprint

| Blueprint area | Classification | Finding |
|---|---|---|
| §3 modular monolith, services own writes | **Implemented and verified** | Controllers delegate; only services write. Storefront `CatalogView` is read-only. |
| §3.2 multisite tenancy | **Implemented and verified** | Per-site prefixes via `WpdbConnection::prefix()`; tenant isolation test exists (`TenantIsolationTest.php`). |
| §5.1 catalogue + variants | **Implemented**, gaps: bulk import/export, barcode uniqueness enforcement, sorting/filtering richness, "out of stock" presentation state | Import has a table only (`imports`); no importer. |
| §5.2 pricing and groups | **Implemented and verified** for retail/wholesale/special/tier/pack; min-order-value **not enforced** at checkout (no checkout exists) | `customer_groups.min_order_value_kobo` exists in schema but is unused in code. |
| §5.2 R1 wholesaler below threshold | **Implemented and verified** | `PricingService.php:78-83`; test in `PricingResolutionTest.php`. |
| §5.3 registration/approval | **Partially implemented** | Staff can create/approve/reject and totals maintain; no storefront registration, no OTP, no self-registration flow, no application audit timeline beyond audit log. |
| §5.4 multi-location inventory | **Implemented and verified** | Locations, transfers, counts, reservations; single-location per line (D-005); no in-transit aggregate, no damaged bucket (schema has no such column). |
| §6 cart | **Not implemented** | No table, service, route, template or test. |
| §6 checkout | **Not implemented** | No checkout service/controller; `OrderService` is ready to be composed, but staff-only REST auth blocks storefront use. |
| §6.4 server-rendered-first storefront | **Implemented and verified** | Swatches are links; `product.js` enhances; theme is PHP-templated (D-024, D-025). |
| §6.5 OTP, account, addresses, orders, tracking, wholesale registration, sitemap, structured data | **Not implemented** | Tables exist; `CustomerService` already supports addresses server-side. |
| §7 unified order service | **Implemented**; channel enum includes `web` (`OrderService.php:29`) | All channels can use it; no channel has been wired except staff REST. |
| §7.2 order/payment/fulfilment state separation | **Implemented and verified** | Three columns: `status`, `payment_status`, `fulfilment_status`; snapshot fields on `order_items`. |
| §7.3 payments | **Implemented except refunds, virtual accounts, reconciliation** | Server-side verification, signed webhooks, replay guard, mismatch alert. No live charge ever. |
| §8 fulfilment/shipping | **Not implemented** (tables only) | No service, no endpoints, no tracking page. |
| §9 notifications/WhatsApp | **Not implemented** (tables only) | Provider route still Q-005. |
| §10 POS/offline | **Not implemented** (tables only) | No device auth, no sync endpoints. |
| §11 mobile/multiple storefronts | Not started; out of current release scope | Storefront is single-theme, single-domain per subsite. |
| §12 permissions/location scope | **Partially implemented** | Capabilities + 6 roles exist; no per-location scoping in services or REST; staff screen read-only (D-021). |
| §12.4 audit | **Implemented** for products, prices, stock, customers, payments, locations | Actor passed explicitly. |
| §13 reporting | **Not implemented** beyond overview | No `/reports/*`. |
| §14 queues/outbox | **Not implemented** | Hooks are synchronous `do_action`; no durable event table, no worker beyond WP-CLI. |
| §15 API/auth/idempotency | **Partially implemented** | Staff capability auth + idempotency for two controllers; no customer auth, no rate limiting, no CORS/session bridge. |
| §16 schema/migrations | **Implemented and verified** | New migrations only; `SchemaMigrator` tracks applied files; PDO tests prove MariaDB compatibility. |
| §17 security/ops | **Partially** | Prepared SQL, escaping, encrypted gateway secrets; missing rate limits, session revocation, backups, monitoring. |
| §18 performance | **Partially** | Grid queries batched (`displayAmounts`, `availableOnlineMany`); product page resolves per variant (N queries, acceptable at pilot size); no page cache. |
| §19 tests | **Partially** | Strong engine tests; no REST tests, no E2E, no cart/checkout; suite not run in this environment. |
| §20 roadmap | Consistent with repo reality | Cart/checkout is indeed the next unblocked slice. |

---

## 4. Missing and incomplete functionality (ordered by release impact)

1. **Cart** — nothing exists. Blocks checkout, wholesale B2B ordering and the
   brief's storefront gate (§22.5).
2. **Checkout** — nothing exists. `OrderService` + `PaymentService` are ready, but
   there is no storefront-authenticated REST surface and no shipping-option source.
3. **Customer session/OTP** — schema (`customer_sessions`, `otp_codes`) and
   `Actor::customer()` exist; no service, controller, rate limiting or delivery.
   Without it, wholesale identity cannot be established in the browser, so
   wholesale checkout cannot complete; `CustomerContext` stays guest-only.
4. **Hold expiry** — `hold_expires_at` is written and never enforced. Every
   unpaid gateway/bank-transfer order holds stock forever. Checkout will make this
   materially worse (every abandoned checkout reserves stock). **P1 correctness
   gap, small to fix** (job + CLI + tests).
5. **Notifications** — no order/payment/OTP messages, no WhatsApp/SMS adapters.
   Checkout must therefore show order confirmation on-screen and must not claim a
   message was sent.
6. **Fulfilment & tracking** — shipments, public tracking, bulk status; needed for
   E2E #1 and the §22.4 gate.
7. **Shipping configuration** — zones/methods tables only; checkout must use
   pickup + flat fee until methods exist (blueprint/handover agreed).
8. **Account area** — profile, addresses (service exists), order history, address
   REST endpoints.
9. **Wholesale registration** — public application form + status page; approval
   already exists staff-side.
10. **Staff management** — read-only; no invite, role change, location assignment
    (D-021 consciously deferred).
11. **Reports** — only dashboard counts; no sales/stock/wholesale/financial
    reports, no CSV.
12. **Refunds, virtual accounts, reconciliation** — deferred (D-019).
13. **Product sitemap + structured data** — absent.
14. **Imports** — table only.
15. **POS/offline sync** — nothing.
16. **Platform layer** — nothing.
17. **Rate limiting / CSRF for customer endpoints** — absent; relevant as soon as
    cart writes exist.

---

## 5. Architectural risks and technical debt

Ranked by likelihood × impact:

1. **Reservations leak because holds never expire** (`OrderService.php:126`,
   no consumer). Abandoned checkouts will permanently consume stock.
   *Mitigation:* expiry job (Action Scheduler or WP-CLI + system cron) that cancels
   expired open orders, releases reservations and raises an alert; test with a
   frozen clock.
2. **REST authentication is staff-only** (`Api::requireCapability`,
   `Api::idempotent` actor key `'staff:'`). A storefront checkout cannot call the
   existing order endpoint without either a new customer-auth controller or
   bypassing REST from templates (which would violate the architecture).
   *Mitigation:* add a customer-session auth helper and a customer-aware idempotency
   method; do not loosen `OrderController` permissions.
3. **`OrderService` trusts caller-supplied customer arrays and group ids**
   (`resolveCustomer` `:632`, `resolveGroup` `:655`). Safe today because only the
   staff controller calls it and does not pass them; dangerous the moment a
   storefront path reuses it. *Mitigation:* storefront code passes `customer_id`
   only, and a test asserts a browser-supplied `customer_group_id` is ignored.
4. **Cart persistence choice must not create a second pricing/stock engine.**
   The blueprint allows a table or a JSON option. A JSON option cannot be queried
   for expiry or concurrency and hides abandoned carts; a table is the right call.
5. **Guest identity for checkout is unspecified.** The brief never says whether an
   account is required to buy. Wholesale definitely needs identity; retail likely
   does not. This must be an explicit decision, not an accident of implementation.
6. **No durable queue/outbox** (§14). Hooks fire inside request lifecycles; once
   notifications exist, a failed send must not fail the order. Introduce the
   outbox with the notifications module, not before (avoid speculative tables).
7. **No REST contract tests.** Controllers are thin, but payload shapes are
   untested; checkout will be the first high-value write from a public surface.
8. **Storefront pricing path per variant** (`CatalogView::product()` calls
   `pricing->resolve()` per variant). Fine for a pilot catalogue; note before
   claiming the §20 latency target at 10k variants.
9. **Cache contract is fragment-only.** No full-page cache for guests; brief §20
   asks for it. Acceptable now, revisit before a merchandising push.
10. **Capability naming diverges from the blueprint's illustrative dotted
    permissions** (`catalog.view`, etc.). Do not rename: existing capabilities are
    wired into roles and tests; map blueprints onto them instead.
11. **`customer_sessions` has no index on `expires_at`** (only `token_hash` unique).
    Session cleanup will table-scan; add an index in the migration that implements
    sessions.
12. **`orders.public_token` exists but no public tracking route**; payment return
    URLs point at `storeUrl/?order=…`, which does nothing yet.

---

## 6. Contradictions between documents and source

| # | Claim | Reality | Resolution |
|---|---|---|---|
| 1 | Blueprint §6.3: “The API document already promises `GET/POST/PATCH /cart`, `POST /checkout/shipping-options`, `POST /checkout`… use the exact route shapes.” | `docs/API.md` lists “Storefront auth, cart, checkout — Phase 4” in its status table but documents **no shapes**. The endpoint names exist only in `docs/BRIEF.md` §18 (line 1812). | Treat the brief's names as binding; define request/response shapes in `docs/API.md` as part of the cart/checkout increment, and record them as the contract. |
| 2 | Blueprint §2.2 quotes 120 tests as the baseline. | 114 test methods in source; 120 executed includes data-provider cases. Suite not runnable here. | Re-run after rebuilding the toolchain; report the real number. |
| 3 | Blueprint §5.2 demands approved wholesalers keep wholesale price below tier minimum. | Already implemented (`PricingService.php:78-83`, `PricingResolutionTest.php`). | No work; extend the test to the cart/checkout quote. |
| 4 | Blueprint §12.1 proposes dotted capability keys (`inventory.adjust`, `reports.financial.view`). | Repository uses `storekeeper_`-prefixed flat names (`adjust_stock`, `view_revenue`, `view_reports`, …) in `Core/Capabilities.php`. | Keep existing names; map any new blueprint permissions onto them. Renaming breaks roles, REST checks and tests for no benefit. |
| 5 | Blueprint §5.4 mentions “in-transit quantity” and damaged/quarantined stock. | Schema has transfers and movements but no in-transit or damaged column; transfers are tracked by status. | Treat as not implemented; add only with the fulfilment/shipping phase and a decision. |
| 6 | Blueprint §6.1 suggests cart as “`{p}carts` table or JSON option per session”. | Neither exists; `customer_sessions` requires a `customer_id`, so it cannot hold guest sessions. | Add `carts`/`cart_items` in a new migration with their own opaque token; record as a decision. |
| 7 | Blueprint §20 says “do not begin OTP before cart/checkout unless dependencies establish a reason”. | Checkout for wholesale **does** depend on identity; retail may not. | Implement cart + guest/retail checkout first; OTP follows immediately and is required for wholesale checkout and E2E #1. State this in the increment report. |

No conflict found where the blueprint contradicts an established *decision*
(D-005, D-019, D-021, D-024, D-025): the blueprint respects all of them.

---

## 7. Unresolved product-owner decisions

Existing (see `docs/OPEN_QUESTIONS.md`): Q-001b OPay credentials, Q-002 name/domain,
Q-004 pilot merchant, Q-005 WhatsApp route, Q-008 legal pages, plus Q-003/Q-006/Q-007
for later phases.

New decisions this assessment recommends recording:

| Proposed | Decision needed | Default if the owner is silent | Blocking? |
|---|---|---|---|
| Q-012 | **Guest checkout:** may a visitor buy with phone + delivery details and no account, or must they register (OTP) first? | Allow guest retail checkout; create/find the customer by phone with `source = 'storefront'`; wholesale always requires sign-in. | Blocks checkout UX, not the cart. |
| Q-013 | **Delivery at launch:** is pickup + one flat store-wide fee acceptable until shipping zones exist? | Yes (handover already recommends it); fee stored in a setting. | Blocks checkout totals. |
| Q-014 | **Cancellation of unpaid web orders:** confirm the brief's hold policy (30 min gateway / 24 h transfer) and that expiry cancels automatically. | Follow brief §9.5 exactly. | Blocks the hold-expiry job from being enabled in production. |

Engineering decisions I can make and record myself (no owner input needed):
cart table shape, cart token format, session cookie hardening, re-pricing on read,
checkout composing `OrderService`, endpoint payload shapes.

---

## 8. Recommended development sequence

Aligned with §22; each item is one reviewable increment.

1. **Environment + baseline** — rebuild the preview/test stack (`docs/PREVIEW.md`),
   run the full suite, confirm the 120-test baseline, record the real numbers.
2. **Hold expiry** — background job + `wp storekeeper orders expire-holds` +
   frozen-clock tests. Small, unblocks safe checkout.
3. **Cart** (§22.5) — migration, `CartService`, guest session token, REST
   `GET/POST/PATCH/DELETE /cart`, `/cart/` storefront page, header entry point,
   tests for guest/retail/wholesale, price change after add, archived variant,
   quantity race, cache bypass.
4. **Checkout** (§22.5) — shipping options (pickup + flat setting), customer
   identity path (guest or session), `CheckoutService` composing `OrderService` +
   `PaymentService`, confirmation page with bank-transfer instructions, idempotency
   for customers, stock revalidation at submit, tests incl. duplicate submission.
5. **OTP + session + account** (§22.5/§11.1) — request/verify, session cookie,
   `GET /me`, addresses, `GET /me/orders`, wholesale registration + status.
6. **Notifications** (§22.4) — template/log infrastructure, adapters (provider
   route per Q-005), outbox, then wire order/OTP events.
7. **Fulfilment + shipping zones + tracking** (§22.4/§14) — shipments, public
   tracking, bulk status.
8. **Reports** (§13) — sales/stock/wholesale minimal set with CSV.
9. **POS + offline sync** (§22.6), then **platform layer** (§22.7).

Rationale for putting hold expiry before checkout: checkout creates the holds the
brief expects to expire; shipping it without the expiry job would mean every
abandoned cart silently locks stock — a correctness regression the current system
does not yet have because it has no public checkout.

---

## 9. Exact scope of the next implementation increment

**Increment: cart + retail/guest checkout (two commits, one review)**

In scope:

1. Migration `0007_carts.php`: `carts` (uuid, token_hash, customer_id nullable,
   currency, status `active|converted|abandoned`, expires_at, created/updated) and
   `cart_items` (cart_id, variant_id, qty, pack_unit_id nullable, added_at,
   UNIQUE(cart_id, variant_id, pack_unit_id)). Index `expires_at`, `customer_id`.
2. `src/Cart/{CartService,CartRepository}.php`: add/update/remove/clear/read;
   re-resolve every line through `PricingService::resolve()` and stock through
   `InventoryService`/`InventoryRepository` (read-only); reject archived variants
   and hidden products; block quantities beyond available unless backorder allowed.
3. `src/Storefront/Session.php`: opaque 32-byte token in an HTTP-only, Secure,
   SameSite=Lax cookie; only `hash('sha256', token)` stored; guest carts expire in
   30 days; merge guest cart into customer cart on sign-in (when OTP lands).
4. `src/Rest/CartController.php`: `GET/POST/PATCH/DELETE /cart` using the cookie
   token (no customer ID accepted from the browser), idempotent writes, `no-store`.
   A new `Api::idempotentFor(string $actorKey, …)` helper instead of reusing the
   staff-only one.
5. `/cart/` route + `templates/storefront/cart.php` + `parts/cart-line.php`;
   header cart count; works without JavaScript (forms), enhanced by `cart.js`.
6. Checkout: `POST /checkout/shipping-options` (pickup + flat fee setting),
   `POST /checkout` → `CheckoutService` → `OrderService::create(channel: 'web',
   customer_id or guest customer via `CustomerService::findOrCreate(..., 'storefront')`,
   shipping from the chosen option, `hold_minutes` per brief §9.5)` → optional
   `PaymentService::initialize()` for gateway choice; confirmation page with order
   number and bank details; group `min_order_value_kobo` enforced for signed-in
   customers.
7. Guardrails: never accept `customer_group_id`, `allow_manual_price`,
   `discount_kobo` or `location_id` from the storefront body; add tests proving
   they are ignored.
8. Tests (`tests/Integration/CartTest.php`, `CheckoutTest.php`): guest add/update,
   retail vs wholesale pricing through the customer seam, price change after add,
   stock drop before checkout, archived variant rejection, duplicate submit creates
   one order, min-order-value block, no stock leak on failed checkout, cart table
   expiry.
9. Docs: `docs/API.md` (shapes), `docs/STOREFRONT.md` (cart/checkout), new
   `docs/PHASE_5_REPORT.md` (storefront completion), `DECISIONS.md` entries
   (cart table, guest session, guest checkout default), `OPEN_QUESTIONS.md`
   (Q-012/Q-013/Q-014).

Explicitly **out** of this increment: OTP/account, notifications, real shipping
zones, refunds, POS, platform layer.

---

## 10. Files and modules expected to change

New:

- `plugins/storekeeper-commerce/migrations/store/0007_carts.php`
- `plugins/storekeeper-commerce/src/Cart/CartService.php`
- `plugins/storekeeper-commerce/src/Cart/CartRepository.php`
- `plugins/storekeeper-commerce/src/Cart/Exception/*` (if needed)
- `plugins/storekeeper-commerce/src/Checkout/CheckoutService.php`
- `plugins/storekeeper-commerce/src/Storefront/Session.php`
- `plugins/storekeeper-commerce/src/Rest/CartController.php`
- `plugins/storekeeper-commerce/src/Rest/CheckoutController.php`
- `plugins/storekeeper-commerce/templates/storefront/cart.php`, `checkout.php`,
  `order-received.php`, `parts/cart-line.php`
- `plugins/storekeeper-commerce/assets/storefront/cart.js`
- `plugins/storekeeper-commerce/tests/Integration/CartTest.php`,
  `CheckoutTest.php`
- `docs/PHASE_5_REPORT.md`

Modified:

- `plugins/storekeeper-commerce/src/Core/Plugin.php` (container registrations)
- `plugins/storekeeper-commerce/storekeeper-commerce.php` (controller registration,
  maybe activation flush)
- `plugins/storekeeper-commerce/src/Rest/Api.php` (customer-aware idempotency)
- `plugins/storekeeper-commerce/src/Storefront/Storefront.php` (routes
  `/cart/`, `/checkout/`, `/order/{number}/`; asset enqueue; cache hooks)
- `plugins/storekeeper-commerce/src/Storefront/CustomerContext.php` (read session)
- `plugins/storekeeper-commerce/assets/storefront/storefront.css`
- `theme/storekeeper-storefront/header.php` (cart link with count)
- `docs/API.md`, `docs/STOREFRONT.md`, `docs/DECISIONS.md`,
  `docs/OPEN_QUESTIONS.md`, `docs/HANDOVER.md`

Possibly modified (pending decision): `plugins/storekeeper-commerce/src/Orders/OrderService.php`
only if checkout needs a new field (e.g. `customer_note`/shipping snapshot);
prefer composing existing inputs to keep the engine stable.

---

## Assumptions and unverified items

- **Assumed:** CI is currently green; the last local suite (120/1,326) still holds
  for `e8d87df` (only docs changed since `63acd0a`). **Unverified.**
- **Assumed:** the storefront theme and preview are rebuildable exactly as
  `docs/PREVIEW.md` describes; the preview is currently absent.
- **Unverified:** Paystack/Flutterwave/OPay behavior against sandboxes; no live
  provider test has ever been run.
- **Unverified:** MariaDB version compatibility of a proposed `carts` migration
  (must be tested via the PDO driver before merge).
