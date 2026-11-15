# Wholesale Commerce Platform — Master Build Brief

> **Codename:** `WSP` (Wholesale Platform). The product name is not chosen yet. Every user-facing name, PHP namespace, text domain, table prefix and REST namespace must come from one constants file so the name can be changed in one place later.
>
> **Document version:** 1.0 · 15 November 2026
> **Owner:** the agency owner (referred to as "the Owner" below)
> **Status:** approved scope, ready to build
> **Licence:** proprietary, closed source. Not for sale or distribution. Not open source.

---

## 0. How to use this document (read first if you are an AI model or a developer)

This file is the single source of truth for building WSP. Read it fully before writing any code.

1. **Read every section once, in order.** Later sections depend on terms defined earlier (see the Glossary, section 4).
2. **Follow the build phases in section 22 in order.** Do not start a later phase before the earlier phase meets its acceptance criteria.
3. **Hard rules are marked MUST / MUST NOT.** They are not suggestions. If you believe a hard rule is wrong, stop and write the reason in `docs/OPEN_QUESTIONS.md` instead of breaking it.
4. **"SHOULD" means default behaviour.** You may deviate only with a written reason in `docs/DECISIONS.md`.
5. **Never invent business facts** (prices, plan amounts, provider names, legal terms). If a value is marked `TBD-OWNER`, keep it configurable and leave a placeholder.
6. **When something is ambiguous**, choose the option that keeps stock and money correct, record the choice in `docs/DECISIONS.md`, and continue.
7. **Keep this document updated.** If the implementation changes the design, update the relevant section in the same commit.

### 0.1 The hard rules (summary)

- WSP MUST NOT depend on WooCommerce, Easy Digital Downloads, Shopify, Bumpa or any other commerce plugin or platform.
- Stock MUST be stored as a ledger of movements in custom tables. Stock MUST NOT be stored in post meta or options.
- Every stock change MUST happen inside a database transaction together with its ledger row.
- Money MUST be stored as integers in the smallest currency unit (kobo for NGN). Never floats, never decimals in PHP arithmetic.
- Every write endpoint MUST accept an idempotency key and MUST be safe to retry.
- Data of one merchant (tenant) MUST never be readable or writable from another tenant's site.
- Storefront customers MUST NOT be WordPress users (see section 7.4). Staff are WordPress users.
- Order lines MUST copy the price, name and SKU at the time of the order. Later catalog changes MUST NOT alter past orders.
- Stock movements MUST be append-only. Corrections are new movements, never edits or deletes.
- All money-affecting and stock-affecting code MUST have automated tests before it is merged.

---

## 1. Business context

### 1.1 What WSP is

WSP is a **hosted commerce service for wholesalers and retailers in Nigeria**, built on WordPress. Merchants do not buy or install software. They **apply, are placed on a monthly plan by the Owner, and get a fully managed store** (back office, storefront, WhatsApp order desk and point of sale) running on the Owner's infrastructure.

It is sold as an **exclusive retainer service**: a setup fee plus a recurring monthly plan. The software is the Owner's private asset. It MAY be packaged as WordPress plugins internally, but it is never sold or handed over as a plugin.

### 1.2 Who it is for

- **Any wholesaler in any product category**: jewellery, fabric, hair and wigs, shoes, bags, cosmetics, phones and accessories, foodstuff, household goods, and so on.
- Merchants who sell **to both resellers (wholesale) and end customers (retail)**, or to only one of the two.
- Businesses that take most orders through **WhatsApp, Instagram DMs, phone calls and walk-in customers**, with the website as a third channel.
- Businesses handling **thousands of products and variants**, with stock changing many times a day.

The system MUST be **category-agnostic**. Nothing in the data model or screens may assume a particular product type. Variant options (colour, size, length, weight, flavour, capacity, and so on) are defined by each merchant.

### 1.3 Where the idea came from

A Nigerian jewellery wholesaler (Instagram account `didi_jewerly`, post dated 13 October 2026, 239 comments at the time it was shared) publicly asked web designers to stop cold-calling and instead build something that actually works for wholesalers. She said wholesalers have already built multiple websites and still find gaps, that inventory across thousands of daily products is their biggest headache, and that they have tried Bumpa, Shopify and similar tools. She currently uses Bumpa for inventory and still finds loopholes.

Her six requirements, paraphrased, are the **core acceptance criteria of this product**:

| # | Requirement (paraphrased) |
|---|---|
| R1 | Wholesale customers can register as wholesalers, so that when they buy below the usual minimum quantity they still get wholesale prices. |
| R2 | Perfect sync between WhatsApp orders, the website and offline (in-shop) sales. A WhatsApp customer must not be pushed through the website checkout, because then someone has to pay the payment-gateway charges. |
| R3 | Easy to update. Anybody on the team can upload products without special training. |
| R4 | When a customer picks a colour, the product images must actually change to show that colour. (Called out as a specific failing of Bumpa.) |
| R5 | One system usable by both wholesalers and retailers. You register, and you get the features for what you registered as. |
| R6 | The business can update delivery progress, so customers can see whether their package has been sent. |

She also said "the list is plenty", meaning more needs will surface. Section 22.1 (Phase 0) covers how we discover them.

### 1.4 Commercial model (decided by the Owner)

| Item | Decision |
|---|---|
| Distribution | Hosted service only. Merchants never receive the code. |
| Revenue | One-time setup fee + monthly plan (retainer). Optional paid add-ons and services. |
| Onboarding | Merchant applies or registers → Owner reviews → Owner assigns a plan → merchant pays → store is provisioned. Self-serve signup MAY be enabled later per plan. |
| Target category | Any wholesaler, any product category. |
| Wholesale access default | **Automatic approval** for anyone who registers as a reseller. Each merchant can switch to manual approval. |
| Payment gateways for merchants' customers | **Paystack, Flutterwave and "GoPay"** at launch (see 1.5). Merchant chooses one or more. |
| WhatsApp | **Both** routes: (a) official WhatsApp Business Platform (automatic messages) and (b) free tap-to-send through the merchant's own WhatsApp Business app. |
| Source code | Proprietary, closed, exclusive to the Owner. |
| Product name | `TBD-OWNER`. Use codename WSP until then. |
| Plan prices and limits | `TBD-OWNER`. Build plans as configurable records. Suggested structure in section 17.3. |

### 1.5 Note on "GoPay"

The Owner listed "GoPay" as a gateway. GoPay is not a widely known Nigerian gateway. It may refer to **OPay** or another provider. **Do not guess.** Build the payment adapter interface (section 13.2) so a third gateway can be added in one class, implement Paystack and Flutterwave first, and record "GoPay = which provider?" in `docs/OPEN_QUESTIONS.md` for the Owner to confirm.

---

## 2. The real problem (why existing tools fail)

Every gap in R1–R6 comes from three design faults shared by general-purpose store builders. WSP is designed around fixing these three faults, not around adding features.

1. **Stock is a number that each channel edits.** Website, app and shop all overwrite one count. When two edit at once, or one channel is skipped, the count drifts. **WSP fix:** one append-only stock ledger; every channel writes movements to it; the visible count is derived.
2. **Price belongs to the product.** One product has one price, maybe with a bulk discount. Wholesalers need price to depend on **who** is buying, not only how many. **WSP fix:** price lists per customer group, customer-specific prices, then quantity tiers.
3. **The tools are web-checkout-first.** Stock only moves cleanly when someone checks out online. These businesses sell in chat and over the counter first. **WSP fix:** an order is a first-class object that staff can create from any channel without a web checkout, and payment is recorded separately from the order.

| Requirement | What goes wrong today | Root fault | WSP mechanism (section) |
|---|---|---|---|
| R1 | Wholesale price unlocked only by cart quantity, so a known wholesaler topping up 3 pieces pays retail | 2 | Customer groups and price resolution (§10) |
| R2 | Staff re-enter WhatsApp orders through the website to deduct stock, and someone pays gateway fees | 1, 3 | Stock ledger (§9), quick order (§11.2), offline POS sync (§15) |
| R3 | Product forms built for a few items edited rarely; variants added one by one | 3 | Fast product entry, variant grid, bulk import (§11.3) |
| R4 | Photos attached to the product, not to the colour | product model too flat | Photos attached to an option value (§8.2, §11.4) |
| R5 | One customer type and one price list, so merchants run two sites | 2 | Customer groups + merchant store mode (§11.5) |
| R6 | Order stops at "paid"; dispatch tracked in someone's head | 3 | Shipments with a timeline and public tracking page (§14) |

---

## 3. Scope

### 3.1 In scope for version 1

**Platform layer (the Owner's business):**
- Merchant applications, Owner review, plan assignment, setup-fee and subscription billing via Paystack.
- Automatic provisioning of a merchant store (a subsite) with sensible defaults.
- Plan limits and feature flags, usage tracking, grace period and suspension for non-payment.
- Owner super-admin console: tenants, plans, revenue, usage, messaging costs, audited "log in as merchant".

**Merchant store (per tenant):**
- Category-agnostic catalog with products, variants, option values, option photos, pack sizes, barcodes.
- Bulk photo upload, variant grid, spreadsheet import (CSV and XLSX), bulk edit, restock screen.
- Multi-location stock ledger with reservations, transfers, counts and adjustments.
- Customer groups (retail, wholesale, custom), price lists, quantity tiers, customer-specific prices.
- Wholesale registration with automatic approval by default.
- Orders from web, WhatsApp, phone, Instagram and counter, all in one list.
- Quick-order screen for staff (the WhatsApp order desk).
- Payments: bank transfer with proof upload, Paystack (card, transfer, USSD, dedicated virtual accounts), Flutterwave, third gateway TBD, cash, card terminal, pay on delivery.
- Shipping zones by Nigerian state and LGA, pickup, park/bus waybills.
- Shipments with status timeline, multiple parcels per order, public tracking page.
- Notifications on WhatsApp (official API), SMS fallback, email, and tap-to-send WhatsApp.
- Offline-capable point of sale as an installable web app (PWA).
- Storefront: shop, product page with option swatches that switch photos, cart, checkout, customer account, order tracking.
- Staff roles and permissions, audit log, alerts (oversell, low stock, ledger mismatch, stale device).
- Reports: sales by channel, by product, by customer group; stock value; low stock; daily cash-up.

### 3.2 Out of scope for version 1 (designed for, not built)

- Courier API integrations with live quotes and labels (Shipbubble, GIG, etc.). Manual courier fields only in v1.
- Multi-currency storefronts. NGN only in v1, but every money field carries a currency code.
- Loyalty points, gift cards, store credit.
- Accounting exports (QuickBooks, Sage). CSV export of orders and payments is in scope.
- Native mobile apps. The PWA covers phone use.
- Marketplace (many merchants on one storefront).
- AI features (auto descriptions, demand forecasting). Hooks must allow adding them later.
- Instagram DM integration. Staff record Instagram orders through quick order.

### 3.3 Non-goals

- WSP is not a general website builder. Merchant marketing pages beyond the storefront are out of scope.
- WSP does not hold merchants' money. Customer payments settle directly into each merchant's own gateway or bank account.

---

## 4. Glossary

| Term | Meaning |
|---|---|
| **Owner** | The agency owner who runs WSP as a service. |
| **Platform / network** | The single WordPress multisite installation that hosts all merchants. |
| **Tenant / merchant** | One business using WSP. Each tenant is one subsite of the network. |
| **Store** | A tenant's subsite: back office, storefront, POS and quick order. |
| **Staff** | People working for a merchant. They are WordPress users with a WSP role on that subsite. |
| **Customer** | A person or business buying from a merchant. Stored in WSP's own `customers` table, not as a WordPress user. |
| **Customer group** | A class of customer (Retail, Wholesale, or custom like Distributor). Decides price list and rules. |
| **Reseller / wholesaler** | A customer in a wholesale-type group. |
| **Product** | A sellable item as the customer thinks of it ("Gold hoop earrings"). |
| **Option / attribute** | A dimension that varies (Colour, Size, Length). Defined per merchant. |
| **Option value** | One value of an option (Red, XL, 18 inches). |
| **Variant** | One buyable combination of option values ("Gold hoop earrings – Silver – Large"). Stock and price live on variants. Products without options have one default variant. |
| **SKU** | Merchant's stock code for a variant. Unique per store. |
| **Pack unit** | How a variant can be sold: piece, dozen, half-dozen, carton, bag. Each has a size in pieces. |
| **Base unit / piece** | The smallest unit the ledger counts in. All stock quantities are in base units. |
| **Location** | A place stock is held: shop, warehouse, or a dedicated online pool. |
| **On hand** | Units physically at a location. |
| **Reserved** | Units promised to open orders not yet handed over or shipped. |
| **Available** | On hand minus reserved. The only number shown to buyers. |
| **Stock movement** | One append-only ledger row that changes on hand and/or reserved. |
| **Reservation / hold** | Reserved units tied to an order, with an optional expiry. |
| **Commit** | Turning a reservation into a sale: on hand and reserved both go down. Happens at handover/dispatch. |
| **Price list** | A set of variant prices for a customer group, with optional quantity tiers. |
| **Quantity tier** | A lower price that applies from a minimum quantity. |
| **Channel** | Where an order came from: web, whatsapp, instagram, phone, counter, other. |
| **Quick order** | The staff screen for entering chat, phone and walk-in orders in seconds. |
| **POS** | Point of sale: the counter app. Works offline. |
| **Device** | A registered phone/tablet running POS, tied to one location. |
| **Shipment / parcel** | A physical package for an order, with its own status timeline. |
| **Idempotency key** | A unique ID sent with a write so retrying it never repeats the effect. |
| **Oversell** | Available stock going below zero, which only offline counter sales can cause. Always raises an alert. |
| **Plan** | The Owner's monthly package for a merchant, with limits and features. |
| **TBD-OWNER** | A value only the Owner can decide. Keep configurable, use a placeholder. |

---

## 5. Users and roles

### 5.1 People

| Persona | Where they work | What they need |
|---|---|---|
| Owner (platform admin) | Network admin console | Approve merchants, assign plans, see revenue and usage, fix problems, log in as a merchant (audited). |
| Merchant owner | Back office on phone and laptop | See sales and stock, set prices and groups, manage staff, approve or adjust anything. |
| Manager | Back office | Everything except billing, staff management and deleting data. |
| Sales / chat staff | Quick order on phone | Enter WhatsApp and phone orders fast, confirm payments, send customers their order details. |
| Cashier | POS on phone or tablet | Sell at the counter, take cash/transfer/terminal payments, keep working offline. |
| Uploader / storekeeper | Back office on phone | Add products, upload photos, receive stock, do stock counts. Cannot see revenue or change prices. |
| Dispatcher | Back office or quick order | Pack orders, mark parcels sent, add waybill and rider details, mark delivered. |
| Retail customer | Storefront | Browse, choose options and see the right photos, pay, track the parcel. |
| Wholesale customer | Storefront and WhatsApp | Register once, always see wholesale prices, reorder fast, pay by transfer, track parcels. |

### 5.2 Permission matrix (store level)

`✓` allowed · `own` only records they created · `—` not allowed.

| Capability | Owner | Manager | Sales | Cashier | Uploader | Dispatcher |
|---|---|---|---|---|---|---|
| View dashboard revenue | ✓ | ✓ | — | — | — | — |
| Create / edit products | ✓ | ✓ | — | — | ✓ | — |
| Change prices and price lists | ✓ | ✓ | — | — | — | — |
| Archive products | ✓ | ✓ | — | — | — | — |
| Receive stock | ✓ | ✓ | — | — | ✓ | — |
| Adjust stock (with reason) | ✓ | ✓ | — | — | ✓ (count only) | — |
| Transfer stock between locations | ✓ | ✓ | — | — | ✓ | — |
| Create orders (quick order) | ✓ | ✓ | ✓ | ✓ | — | — |
| Sell on POS | ✓ | ✓ | ✓ | ✓ | — | — |
| Give manual discount | ✓ | ✓ | up to limit | up to limit | — | — |
| Override price on an order line | ✓ | ✓ | — | — | — | — |
| Confirm bank-transfer payment | ✓ | ✓ | ✓ | ✓ | — | — |
| Cancel order | ✓ | ✓ | own, unpaid only | own, unpaid only | — | — |
| Refund | ✓ | ✓ | — | — | — | — |
| Update shipment status | ✓ | ✓ | ✓ | — | — | ✓ |
| Manage customers and groups | ✓ | ✓ | view + create | view + create | — | — |
| Approve wholesale applications | ✓ | ✓ | — | — | — | — |
| Manage staff | ✓ | — | — | — | — | — |
| Store settings, gateways, messaging | ✓ | — | — | — | — | — |
| Register POS devices | ✓ | ✓ | — | — | — | — |
| Reports | ✓ | ✓ | own sales | own sales + cash-up | stock only | — |
| Resolve alerts | ✓ | ✓ | — | — | stock alerts | — |
| View audit log | ✓ | ✓ | — | — | — | — |
| View plan and billing | ✓ | — | — | — | — | — |

Implement as WordPress capabilities prefixed `wsp_` (e.g. `wsp_edit_products`, `wsp_edit_prices`, `wsp_adjust_stock`, `wsp_confirm_payments`, `wsp_refund`, `wsp_manage_staff`). Roles map to sets of capabilities. A merchant MAY create custom roles from the capability list on plans that allow it.

Discount limits for Sales and Cashier are store settings (percentage, default 0%).

---

## 6. System architecture

### 6.1 Overview

```
                ┌────────────────────────── WordPress multisite network (one install) ──────────────────────────┐
                │                                                                                              │
                │  Main site (Owner)                     Subsite per merchant (tenant)                          │
                │  ┌──────────────────────┐              ┌──────────────────────────────────────────────────┐   │
                │  │ Marketing + apply    │              │ Screens (clients of the REST API)                │   │
                │  │ Owner console        │              │  Back office │ Quick order │ POS (PWA) │ Storefront│   │
                │  │ Billing (Paystack)   │              └───────────────────────┬──────────────────────────┘   │
                │  │ Provisioning jobs    │                                      │                              │
                │  └──────────┬───────────┘              ┌───────────────────────▼──────────────────────────┐   │
                │             │                          │ REST API  /wp-json/wsp/v1/...  (idempotent writes) │   │
                │             │                          └───────────────────────┬──────────────────────────┘   │
                │             │                          ┌───────────────────────▼──────────────────────────┐   │
                │             │                          │ Commerce engine (plain PHP services)              │   │
                │             │                          │ Catalog · Pricing · STOCK LEDGER · Orders ·       │   │
                │             │                          │ Payments · Fulfilment · Customers · Notifications │   │
                │             │                          └───────┬──────────────┬──────────────┬─────────────┘   │
                │             │                                  │              │              │                 │
                │  Network tables (wp_wsp_*)            Site tables (wp_N_wsp_*)  Job queue      Adapters         │
                │  tenants, plans, subscriptions…       products, stock, orders…  (Action        (gateways,       │
                │                                                                Scheduler)      WhatsApp, SMS)   │
                └──────────────────────────────────────────────────────────────────────────────────────────────┘
```

Principles:

1. **Engine first, screens second.** All business rules live in PHP service classes with no knowledge of HTTP or HTML. Screens call the REST API. The API calls the engine. Only the engine writes to the tables.
2. **No screen changes stock or prices directly.** This is what keeps channels from drifting.
3. **Everything that can wait runs in a background job** (messages, imports, hold expiry, reports, provisioning).
4. **Adapters isolate the outside world.** Each payment gateway, messaging provider and courier is one class implementing a shared interface.
5. **Hooks at every state change** (`do_action('wsp/order/paid', $order)` etc.) form the extension system and the path to paid add-ons.

### 6.2 Technology choices

| Layer | Choice | Notes |
|---|---|---|
| CMS / host app | WordPress multisite, latest stable (minimum 6.5) | Subdomain or subdirectory install; custom domains via WordPress's built-in domain mapping. |
| Language (server) | PHP 8.2+ with `declare(strict_types=1);` | PSR-4 autoloading via Composer. PSR-12 style. |
| Database | MySQL 8.0+ or MariaDB 10.6+, InnoDB only | Transactions and row locking are required. |
| Object cache | Redis (persistent object cache) | Required in production. |
| Background jobs | Action Scheduler (bundled as a Composer/library dependency, run by real system cron or WP-CLI runner) | WP-Cron alone MUST NOT be relied on. |
| Back office UI | React via `@wordpress/element`, `@wordpress/components`, `@wordpress/data`, built with `@wordpress/scripts` | Mounted inside wp-admin pages. Phone-first layout. |
| Quick order | Same React app, separate route, optimised for one-hand phone use | |
| POS | Standalone PWA (React, Workbox service worker, IndexedDB via Dexie.js) served from the subsite | Installable, offline-first. |
| Storefront | Server-rendered PHP blocks (`block.json`), WordPress Interactivity API for swatches, cart and checkout interactivity | Works in any block theme. Elementor widgets as a thin wrapper later. |
| Build tooling | Node 20 LTS, npm workspaces | |
| Testing | PHPUnit + WordPress test suite (multisite mode), Jest for JS, Playwright for end-to-end | See section 21. |
| Payments | Paystack, Flutterwave, third gateway TBD (adapter) | |
| Messaging | WhatsApp Cloud API (direct or through a provider), SMS provider adapter (e.g. Termii), WordPress mail for email | |
| Hosting | VPS or managed cloud with root access, Nginx, PHP-FPM, Redis, automated daily backups, wildcard or automatic SSL for subdomains and mapped domains | Shared hosting MUST NOT be used. |

### 6.3 Why multisite

- One codebase and one update for every merchant.
- Each merchant gets an isolated set of tables (`wp_{blog_id}_wsp_*`), its own media library, its own staff, its own domain.
- WordPress handles users, roles, media, REST framework, nonces, themes and the block editor, so we do not rebuild them.
- Platform tables (tenants, plans, billing) live once at network level (`wp_wsp_*` using `$wpdb->base_prefix`).

### 6.4 Tenant isolation rules

- Every engine call runs in the context of exactly one blog ID. Code MUST use `$wpdb->prefix` for store tables and `$wpdb->base_prefix` only for network tables.
- Any code that loops over tenants (Owner reports, billing jobs) MUST use `switch_to_blog()` / `restore_current_blog()` in a `try/finally` block.
- REST requests resolve the tenant from the host name. A token or session from tenant A MUST be rejected on tenant B.
- Staff users are network users (WordPress multisite rule), but WSP capabilities are granted per site. A user with roles on two stores sees only the store whose URL they are on.
- Automated isolation tests (section 21) MUST create two tenants and assert no read or write crosses between them.

---

## 7. Code organisation

### 7.1 Repository layout

```
wsp/                                  # monorepo, private Git repository
├── docs/
│   ├── BRIEF.md                      # this document
│   ├── DECISIONS.md                  # decisions taken during build, with reasons
│   ├── OPEN_QUESTIONS.md             # questions for the Owner
│   └── API.md                        # generated/maintained endpoint reference
├── plugins/
│   ├── wsp-platform/                 # network-activated: tenants, plans, billing, provisioning, Owner console
│   │   ├── wsp-platform.php
│   │   ├── src/
│   │   └── tests/
│   ├── wsp-commerce/                 # network-activated, runs per subsite: the commerce engine + API + screens
│   │   ├── wsp-commerce.php
│   │   ├── src/
│   │   │   ├── Core/                 # bootstrap, constants, container, migrations, hooks registry
│   │   │   ├── Catalog/
│   │   │   ├── Pricing/
│   │   │   ├── Inventory/            # ledger, reservations, counts, transfers
│   │   │   ├── Customers/            # customers, groups, OTP auth, sessions
│   │   │   ├── Orders/
│   │   │   ├── Payments/             # gateway interface + adapters
│   │   │   ├── Shipping/
│   │   │   ├── Fulfilment/
│   │   │   ├── Notifications/        # channels, templates, routing, logs
│   │   │   ├── Sync/                 # POS device registration, batch sync
│   │   │   ├── Import/               # CSV/XLSX import, Bumpa import
│   │   │   ├── Reports/
│   │   │   ├── Alerts/
│   │   │   ├── Audit/
│   │   │   ├── Rest/                 # controllers only; no business logic
│   │   │   ├── Admin/                # wp-admin menu registration, asset loading
│   │   │   └── Storefront/           # blocks, templates, routes
│   │   ├── assets/                   # built JS/CSS
│   │   ├── migrations/               # versioned schema files
│   │   └── tests/
│   └── wsp-mu-loader/                # must-use plugin: load order, environment guards
├── apps/
│   ├── admin/                        # React back office + quick order (npm workspace)
│   ├── pos/                          # React PWA (npm workspace)
│   └── storefront/                   # block JS (Interactivity API stores), styles
├── theme/
│   └── wsp-storefront/               # a lean default block theme for merchant stores
├── tools/                            # seed data (Nigerian states and LGAs), scripts, WP-CLI commands
├── composer.json
├── package.json
└── docker-compose.yml                # local multisite + MySQL + Redis + Mailpit
```

### 7.2 Naming

All names come from `src/Core/Brand.php` (constants) so the product can be renamed:

| Thing | Value |
|---|---|
| PHP namespace | `WSP\Commerce\…`, `WSP\Platform\…` |
| Table prefix (site) | `{$wpdb->prefix}wsp_` |
| Table prefix (network) | `{$wpdb->base_prefix}wsp_` |
| REST namespace | `wsp/v1` |
| Hook prefix | `wsp/` (e.g. `wsp/order/created`) |
| Option prefix | `wsp_` |
| Capability prefix | `wsp_` |
| Text domain | `wsp` (all strings translatable) |

### 7.3 Engine conventions

- Services are classes with constructor injection (a small container in `Core`). No static business logic, no global state other than `$wpdb`.
- One repository class per table group; repositories return value objects, never raw arrays to controllers.
- Domain errors throw typed exceptions (`InsufficientStock`, `PriceNotFound`, `InvalidTransition`, `DuplicateRequest`) that the REST layer maps to HTTP codes and stable error codes.
- Times stored in UTC as `DATETIME(3)`; displayed in the store's timezone (default `Africa/Lagos`).
- Phone numbers stored in E.164 (`+2348012345678`). Input accepts `0801…`, `234801…`, `+234 801…`.
- Money is an integer + currency code. A `Money` value object handles arithmetic and formatting (`₦12,500.00`).
- Migrations are versioned PHP files; the plugin records the schema version per site and runs pending migrations on activation and on a network upgrade command (`wp wsp migrate --network`).

### 7.4 Why customers are not WordPress users

In multisite, the users table is shared across the whole network. If a buyer at Store A became a WordPress user, the same record would be shared with Store B and could leak across tenants or block a separate sign-up. Therefore:

- **Customers** live in each store's `wsp_customers` table and sign in with phone + one-time code (OTP). WSP issues its own session token (HTTP-only secure cookie for the storefront).
- **Staff** are WordPress users with WSP roles on that subsite, and sign in through the normal WordPress login (with optional OTP).

---

## 8. Data model

All store tables are per subsite and use `{p}` = `$wpdb->prefix . 'wsp_'`. Network tables use `{n}` = `$wpdb->base_prefix . 'wsp_'`. All tables use InnoDB, `utf8mb4`, `utf8mb4_unicode_ci`. Every table has `created_at` and, where rows change, `updated_at`, both `DATETIME(3)` UTC. Every business entity also has a `uuid CHAR(36)` used in URLs and APIs; numeric IDs are internal.

The DDL below is the specification. Column types, uniqueness and indexes are binding; you MAY add columns and indexes, but MUST NOT remove or loosen these.

### 8.1 Catalog

```sql
CREATE TABLE {p}categories (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid          CHAR(36) NOT NULL UNIQUE,
  parent_id     BIGINT UNSIGNED NULL,
  name          VARCHAR(191) NOT NULL,
  slug          VARCHAR(191) NOT NULL UNIQUE,
  image_id      BIGINT UNSIGNED NULL,          -- WordPress attachment ID
  sort_order    INT NOT NULL DEFAULT 0,
  created_at    DATETIME(3) NOT NULL,
  updated_at    DATETIME(3) NOT NULL,
  KEY parent (parent_id)
);

CREATE TABLE {p}products (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid              CHAR(36) NOT NULL UNIQUE,
  name              VARCHAR(255) NOT NULL,
  slug              VARCHAR(191) NOT NULL UNIQUE,
  description       LONGTEXT NULL,               -- no length limit (a known Bumpa complaint)
  short_description TEXT NULL,
  category_id       BIGINT UNSIGNED NULL,
  status            ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
  visibility        ENUM('all','wholesale_only','retail_only','hidden') NOT NULL DEFAULT 'all',
  base_unit_label   VARCHAR(32) NOT NULL DEFAULT 'piece',
  track_stock       TINYINT(1) NOT NULL DEFAULT 1,
  allow_backorder   TINYINT(1) NOT NULL DEFAULT 0,
  tags              VARCHAR(500) NULL,           -- comma-separated, searchable
  seo_title         VARCHAR(255) NULL,
  seo_description   VARCHAR(500) NULL,
  created_by        BIGINT UNSIGNED NULL,        -- WP user ID
  created_at        DATETIME(3) NOT NULL,
  updated_at        DATETIME(3) NOT NULL,
  KEY category (category_id),
  KEY status (status),
  FULLTEXT KEY search (name, tags, short_description)
);

-- Options (attributes) are defined per store, so any product category works.
CREATE TABLE {p}options (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(100) NOT NULL,            -- "Colour", "Size", "Length", "Flavour"
  slug         VARCHAR(100) NOT NULL UNIQUE,
  display      ENUM('color','image','label','dropdown') NOT NULL DEFAULT 'label',
  sort_order   INT NOT NULL DEFAULT 0,
  created_at   DATETIME(3) NOT NULL
);

CREATE TABLE {p}option_values (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  option_id    BIGINT UNSIGNED NOT NULL,
  value        VARCHAR(100) NOT NULL,            -- "Rose gold", "XL", "18 inches"
  slug         VARCHAR(100) NOT NULL,
  color_hex    CHAR(7) NULL,                     -- used when option.display = 'color'
  image_id     BIGINT UNSIGNED NULL,             -- swatch image when display = 'image'
  sort_order   INT NOT NULL DEFAULT 0,
  UNIQUE KEY option_slug (option_id, slug)
);

CREATE TABLE {p}product_options (                -- which options a product's variants are built from
  product_id   BIGINT UNSIGNED NOT NULL,
  option_id    BIGINT UNSIGNED NOT NULL,
  sort_order   INT NOT NULL DEFAULT 0,
  is_media_option TINYINT(1) NOT NULL DEFAULT 0,  -- exactly one option per product drives photos (usually Colour)
  PRIMARY KEY (product_id, option_id)
);

CREATE TABLE {p}variants (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid           CHAR(36) NOT NULL UNIQUE,
  product_id     BIGINT UNSIGNED NOT NULL,
  sku            VARCHAR(64) NOT NULL UNIQUE,     -- auto-generated if left blank
  barcode        VARCHAR(64) NULL,
  title          VARCHAR(255) NOT NULL,           -- derived: "Rose gold / Large"; "Default" if no options
  weight_grams   INT UNSIGNED NULL,
  cost_kobo      BIGINT NULL,                     -- purchase cost, for profit reports; hidden from Uploader
  low_stock_threshold INT NULL,                   -- per variant; falls back to store default
  status         ENUM('active','archived') NOT NULL DEFAULT 'active',
  sort_order     INT NOT NULL DEFAULT 0,
  created_at     DATETIME(3) NOT NULL,
  updated_at     DATETIME(3) NOT NULL,
  KEY product (product_id),
  KEY barcode (barcode)
);

CREATE TABLE {p}variant_option_values (
  variant_id       BIGINT UNSIGNED NOT NULL,
  option_value_id  BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (variant_id, option_value_id),
  KEY value (option_value_id)
);

-- Photos attach to the product, optionally scoped to one value of the product's media option.
-- option_value_id NULL = general product photo shown for every option.
-- This is what makes "pick Red → see red photos" work (R4), and all sizes of Red share the red photos.
CREATE TABLE {p}product_media (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id       BIGINT UNSIGNED NOT NULL,
  option_value_id  BIGINT UNSIGNED NULL,
  attachment_id    BIGINT UNSIGNED NOT NULL,
  sort_order       INT NOT NULL DEFAULT 0,
  is_swatch_source TINYINT(1) NOT NULL DEFAULT 0, -- crop of this photo can be the swatch
  KEY product_value (product_id, option_value_id, sort_order)
);

-- Pack units: piece is implicit (pieces = 1). product_id NULL = store-wide unit (e.g. "Dozen" = 12).
CREATE TABLE {p}pack_units (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id   BIGINT UNSIGNED NULL,
  name         VARCHAR(50) NOT NULL,             -- "Dozen", "Carton", "Bag"
  pieces       INT UNSIGNED NOT NULL,            -- > 1
  sort_order   INT NOT NULL DEFAULT 0,
  KEY product (product_id)
);
```

Rules:
- A product with no options has exactly one variant titled "Default". Screens hide variants for such products.
- Changing a product's options after variants exist MUST go through a "rebuild variants" action that archives variants no longer valid (never deletes ones with ledger history).
- Archived variants keep their stock history and still appear on past orders.

### 8.2 Pricing and customers

```sql
CREATE TABLE {p}price_lists (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100) NOT NULL,             -- "Retail", "Wholesale"
  currency    CHAR(3) NOT NULL DEFAULT 'NGN',
  is_base     TINYINT(1) NOT NULL DEFAULT 0,     -- exactly one base (retail) list per store
  created_at  DATETIME(3) NOT NULL
);

CREATE TABLE {p}prices (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  price_list_id  BIGINT UNSIGNED NOT NULL,
  variant_id     BIGINT UNSIGNED NOT NULL,
  pack_unit_id   BIGINT UNSIGNED NULL,           -- NULL = price per piece
  min_qty        INT UNSIGNED NOT NULL DEFAULT 1,-- quantity tier, counted in the row's unit
  amount_kobo    BIGINT NOT NULL,
  updated_at     DATETIME(3) NOT NULL,
  UNIQUE KEY tier (price_list_id, variant_id, pack_unit_id, min_qty),
  KEY variant (variant_id)
);

CREATE TABLE {p}customer_groups (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid                  CHAR(36) NOT NULL UNIQUE,
  name                  VARCHAR(100) NOT NULL,   -- "Retail", "Wholesale", "Distributor"
  type                  ENUM('retail','wholesale','custom') NOT NULL,
  price_list_id         BIGINT UNSIGNED NOT NULL,
  approval              ENUM('auto','manual') NOT NULL DEFAULT 'auto',
  is_default            TINYINT(1) NOT NULL DEFAULT 0, -- default group for guests and new sign-ups (Retail)
  is_self_registerable  TINYINT(1) NOT NULL DEFAULT 1, -- shown as a choice at sign-up
  min_order_value_kobo  BIGINT NULL,             -- optional per-order minimum for this group
  ignore_item_minimums  TINYINT(1) NOT NULL DEFAULT 1, -- R1: approved members get list price at any quantity
  can_pay_on_delivery   TINYINT(1) NOT NULL DEFAULT 0,
  credit_limit_kobo     BIGINT NULL,             -- reserved for later (credit sales); not used in v1
  created_at            DATETIME(3) NOT NULL
);

CREATE TABLE {p}customers (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid             CHAR(36) NOT NULL UNIQUE,
  phone_e164       VARCHAR(20) NOT NULL UNIQUE,
  name             VARCHAR(191) NOT NULL,
  business_name    VARCHAR(191) NULL,
  email            VARCHAR(191) NULL,
  group_id         BIGINT UNSIGNED NOT NULL,
  requested_group_id BIGINT UNSIGNED NULL,       -- set while a manual approval is pending
  approval_status  ENUM('approved','pending','rejected') NOT NULL DEFAULT 'approved',
  source           ENUM('storefront','staff','pos','import') NOT NULL,
  whatsapp_opt_in  TINYINT(1) NOT NULL DEFAULT 1, -- transactional updates
  marketing_opt_in TINYINT(1) NOT NULL DEFAULT 0,
  notes            TEXT NULL,
  total_spent_kobo BIGINT NOT NULL DEFAULT 0,     -- denormalised, updated on payment
  order_count      INT NOT NULL DEFAULT 0,
  last_order_at    DATETIME(3) NULL,
  created_at       DATETIME(3) NOT NULL,
  updated_at       DATETIME(3) NOT NULL,
  KEY grp (group_id),
  KEY name (name)
);

CREATE TABLE {p}customer_prices (                -- special price agreed with one customer
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id   BIGINT UNSIGNED NOT NULL,
  variant_id    BIGINT UNSIGNED NOT NULL,
  pack_unit_id  BIGINT UNSIGNED NULL,
  amount_kobo   BIGINT NOT NULL,
  valid_from    DATETIME(3) NULL,
  valid_to      DATETIME(3) NULL,
  UNIQUE KEY one (customer_id, variant_id, pack_unit_id)
);

CREATE TABLE {p}addresses (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id    BIGINT UNSIGNED NOT NULL,
  label          VARCHAR(50) NULL,               -- "Shop", "Home"
  recipient_name VARCHAR(191) NOT NULL,
  phone_e164     VARCHAR(20) NOT NULL,
  line1          VARCHAR(255) NOT NULL,
  line2          VARCHAR(255) NULL,
  landmark       VARCHAR(255) NULL,
  city           VARCHAR(100) NULL,
  lga            VARCHAR(100) NOT NULL,
  state          VARCHAR(50) NOT NULL,           -- one of 36 states + FCT
  is_default     TINYINT(1) NOT NULL DEFAULT 0,
  KEY customer (customer_id)
);

CREATE TABLE {p}customer_sessions (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id  BIGINT UNSIGNED NOT NULL,
  token_hash   CHAR(64) NOT NULL UNIQUE,          -- SHA-256 of the random token; raw token only in cookie
  expires_at   DATETIME(3) NOT NULL,
  ip           VARCHAR(45) NULL,
  user_agent   VARCHAR(255) NULL,
  created_at   DATETIME(3) NOT NULL
);

CREATE TABLE {p}otp_codes (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  phone_e164   VARCHAR(20) NOT NULL,
  code_hash    CHAR(64) NOT NULL,
  channel      ENUM('whatsapp','sms') NOT NULL,
  purpose      ENUM('login','register','staff_login') NOT NULL,
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at   DATETIME(3) NOT NULL,
  consumed_at  DATETIME(3) NULL,
  created_at   DATETIME(3) NOT NULL,
  KEY phone (phone_e164, created_at)
);
```

### 8.3 Inventory

```sql
CREATE TABLE {p}locations (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid             CHAR(36) NOT NULL UNIQUE,
  name             VARCHAR(100) NOT NULL,         -- "Balogun shop", "Warehouse", "Online stock"
  type             ENUM('shop','warehouse','online_pool') NOT NULL,
  sellable_online  TINYINT(1) NOT NULL DEFAULT 1, -- storefront availability sums these locations
  online_priority  INT NOT NULL DEFAULT 0,        -- order in which web orders reserve from locations
  address          VARCHAR(500) NULL,
  is_pickup_point  TINYINT(1) NOT NULL DEFAULT 0,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at       DATETIME(3) NOT NULL
);

-- Fast lookup of current levels. Always derivable from stock_movements (see 9.6).
CREATE TABLE {p}stock_levels (
  variant_id    BIGINT UNSIGNED NOT NULL,
  location_id   BIGINT UNSIGNED NOT NULL,
  on_hand       INT NOT NULL DEFAULT 0,           -- may go negative ONLY through offline POS sync (oversell)
  reserved      INT NOT NULL DEFAULT 0,           -- never negative
  last_movement_id BIGINT UNSIGNED NULL,
  updated_at    DATETIME(3) NOT NULL,
  PRIMARY KEY (variant_id, location_id),
  KEY location (location_id),
  CONSTRAINT reserved_nonneg CHECK (reserved >= 0)
);

-- The ledger. Append-only: no UPDATE or DELETE ever.
CREATE TABLE {p}stock_movements (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid              CHAR(36) NOT NULL UNIQUE,
  variant_id        BIGINT UNSIGNED NOT NULL,
  location_id       BIGINT UNSIGNED NOT NULL,
  type              ENUM('receive','reserve','release','commit','counter_sale',
                         'return','transfer_out','transfer_in','adjust','opening') NOT NULL,
  on_hand_delta     INT NOT NULL DEFAULT 0,       -- in base units (pieces)
  reserved_delta    INT NOT NULL DEFAULT 0,
  reason            VARCHAR(64) NULL,             -- for adjust: 'count','damaged','lost','found','correction'
  order_id          BIGINT UNSIGNED NULL,
  order_item_id     BIGINT UNSIGNED NULL,
  transfer_id       BIGINT UNSIGNED NULL,
  count_id          BIGINT UNSIGNED NULL,
  receipt_id        BIGINT UNSIGNED NULL,
  actor_type        ENUM('staff','customer','system','device') NOT NULL,
  actor_id          BIGINT UNSIGNED NULL,
  device_id         BIGINT UNSIGNED NULL,
  client_created_at DATETIME(3) NULL,             -- device clock for offline movements
  note              VARCHAR(255) NULL,
  created_at        DATETIME(3) NOT NULL,
  KEY variant_location (variant_id, location_id, id),
  KEY order_ref (order_id),
  KEY created (created_at)
);

CREATE TABLE {p}stock_receipts (                  -- a delivery from a supplier
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid         CHAR(36) NOT NULL UNIQUE,
  location_id  BIGINT UNSIGNED NOT NULL,
  supplier     VARCHAR(191) NULL,
  reference    VARCHAR(100) NULL,
  total_cost_kobo BIGINT NULL,
  created_by   BIGINT UNSIGNED NOT NULL,
  created_at   DATETIME(3) NOT NULL
);

CREATE TABLE {p}stock_transfers (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid             CHAR(36) NOT NULL UNIQUE,
  from_location_id BIGINT UNSIGNED NOT NULL,
  to_location_id   BIGINT UNSIGNED NOT NULL,
  status           ENUM('draft','sent','received','cancelled') NOT NULL DEFAULT 'draft',
  created_by       BIGINT UNSIGNED NOT NULL,
  created_at       DATETIME(3) NOT NULL,
  sent_at          DATETIME(3) NULL,
  received_at      DATETIME(3) NULL
);

CREATE TABLE {p}stock_transfer_lines (
  transfer_id  BIGINT UNSIGNED NOT NULL,
  variant_id   BIGINT UNSIGNED NOT NULL,
  qty          INT UNSIGNED NOT NULL,
  qty_received INT UNSIGNED NULL,
  PRIMARY KEY (transfer_id, variant_id)
);

CREATE TABLE {p}stock_counts (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid         CHAR(36) NOT NULL UNIQUE,
  location_id  BIGINT UNSIGNED NOT NULL,
  scope        VARCHAR(255) NULL,                -- e.g. category or shelf label
  status       ENUM('open','submitted','applied','cancelled') NOT NULL DEFAULT 'open',
  created_by   BIGINT UNSIGNED NOT NULL,
  created_at   DATETIME(3) NOT NULL,
  applied_at   DATETIME(3) NULL
);

CREATE TABLE {p}stock_count_lines (
  count_id      BIGINT UNSIGNED NOT NULL,
  variant_id    BIGINT UNSIGNED NOT NULL,
  expected_qty  INT NOT NULL,                     -- on_hand snapshot when the line was counted
  counted_qty   INT NOT NULL,
  counted_by    BIGINT UNSIGNED NOT NULL,
  counted_at    DATETIME(3) NOT NULL,
  PRIMARY KEY (count_id, variant_id)
);
```

### 8.4 Orders, payments, fulfilment

```sql
CREATE TABLE {p}orders (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid                CHAR(36) NOT NULL UNIQUE,
  number              VARCHAR(32) NOT NULL UNIQUE,  -- human number, store prefix + sequence: "DJ-10423"
  channel             ENUM('web','whatsapp','instagram','phone','counter','other') NOT NULL,
  status              ENUM('draft','open','completed','cancelled') NOT NULL DEFAULT 'open',
  payment_status      ENUM('unpaid','awaiting_confirmation','partially_paid','paid',
                           'partially_refunded','refunded','pay_on_delivery') NOT NULL DEFAULT 'unpaid',
  fulfilment_status   ENUM('unfulfilled','partially_fulfilled','fulfilled','returned') NOT NULL DEFAULT 'unfulfilled',
  customer_id         BIGINT UNSIGNED NULL,          -- NULL = walk-in / anonymous counter sale
  customer_group_id   BIGINT UNSIGNED NOT NULL,      -- group used for pricing at order time
  location_id         BIGINT UNSIGNED NOT NULL,      -- main location stock is reserved from
  currency            CHAR(3) NOT NULL DEFAULT 'NGN',
  subtotal_kobo       BIGINT NOT NULL,
  discount_kobo       BIGINT NOT NULL DEFAULT 0,
  shipping_kobo       BIGINT NOT NULL DEFAULT 0,
  fee_kobo            BIGINT NOT NULL DEFAULT 0,     -- e.g. gateway fee passed to customer
  total_kobo          BIGINT NOT NULL,
  paid_kobo           BIGINT NOT NULL DEFAULT 0,
  refunded_kobo       BIGINT NOT NULL DEFAULT 0,
  shipping_method_id  BIGINT UNSIGNED NULL,
  shipping_address    JSON NULL,                     -- snapshot of the address at order time
  hold_expires_at     DATETIME(3) NULL,              -- reservation expiry; NULL = no expiry
  public_token        CHAR(32) NOT NULL UNIQUE,      -- random; used in the no-login tracking link
  idempotency_key     VARCHAR(64) NOT NULL UNIQUE,
  created_by_type     ENUM('staff','customer','device','system') NOT NULL,
  created_by_id       BIGINT UNSIGNED NULL,
  device_id           BIGINT UNSIGNED NULL,
  client_created_at   DATETIME(3) NULL,
  customer_note       TEXT NULL,
  staff_note          TEXT NULL,
  cancel_reason       VARCHAR(255) NULL,
  created_at          DATETIME(3) NOT NULL,
  updated_at          DATETIME(3) NOT NULL,
  cancelled_at        DATETIME(3) NULL,
  completed_at        DATETIME(3) NULL,
  KEY status (status, payment_status, fulfilment_status),
  KEY customer (customer_id),
  KEY channel_created (channel, created_at),
  KEY hold (hold_expires_at)
);

CREATE TABLE {p}order_items (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id           BIGINT UNSIGNED NOT NULL,
  variant_id         BIGINT UNSIGNED NOT NULL,
  product_name       VARCHAR(255) NOT NULL,          -- snapshot
  variant_title      VARCHAR(255) NOT NULL,          -- snapshot
  sku                VARCHAR(64) NOT NULL,           -- snapshot
  image_attachment_id BIGINT UNSIGNED NULL,          -- snapshot of the photo shown at order time
  pack_unit_id       BIGINT UNSIGNED NULL,
  pack_name          VARCHAR(50) NOT NULL DEFAULT 'piece',
  pack_pieces        INT UNSIGNED NOT NULL DEFAULT 1,
  qty                INT UNSIGNED NOT NULL,           -- in packs
  qty_pieces         INT UNSIGNED NOT NULL,           -- qty × pack_pieces; what the ledger uses
  qty_fulfilled_pieces INT UNSIGNED NOT NULL DEFAULT 0,
  unit_price_kobo    BIGINT NOT NULL,                 -- per pack
  list_price_kobo    BIGINT NOT NULL,                 -- base retail price per pack, for "you saved"
  price_source       ENUM('customer','group','group_tier','base','base_tier','manual') NOT NULL,
  discount_kobo      BIGINT NOT NULL DEFAULT 0,
  line_total_kobo    BIGINT NOT NULL,
  location_id        BIGINT UNSIGNED NOT NULL,        -- where this line's stock is reserved
  KEY order_ref (order_id),
  KEY variant (variant_id)
);

CREATE TABLE {p}payments (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid               CHAR(36) NOT NULL UNIQUE,
  order_id           BIGINT UNSIGNED NOT NULL,
  method             ENUM('bank_transfer','virtual_account','paystack','flutterwave','gateway3',
                          'cash','pos_terminal','pay_on_delivery','other') NOT NULL,
  status             ENUM('pending','awaiting_confirmation','succeeded','failed','cancelled') NOT NULL,
  amount_kobo        BIGINT NOT NULL,
  gateway_reference  VARCHAR(100) NULL,
  gateway_fee_kobo   BIGINT NULL,
  gateway_payload    JSON NULL,                       -- raw verified response, for audit
  proof_attachment_id BIGINT UNSIGNED NULL,           -- uploaded transfer receipt
  sender_name        VARCHAR(191) NULL,               -- "paid from" name on transfer
  confirmed_by       BIGINT UNSIGNED NULL,            -- staff user who confirmed a manual payment
  confirmed_at       DATETIME(3) NULL,
  idempotency_key    VARCHAR(64) NOT NULL UNIQUE,
  created_at         DATETIME(3) NOT NULL,
  updated_at         DATETIME(3) NOT NULL,
  UNIQUE KEY gw_ref (method, gateway_reference),
  KEY order_ref (order_id)
);

CREATE TABLE {p}refunds (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid           CHAR(36) NOT NULL UNIQUE,
  order_id       BIGINT UNSIGNED NOT NULL,
  payment_id     BIGINT UNSIGNED NULL,
  amount_kobo    BIGINT NOT NULL,
  method         VARCHAR(32) NOT NULL,               -- 'gateway','bank_transfer','cash'
  reason         VARCHAR(255) NOT NULL,
  restock        TINYINT(1) NOT NULL DEFAULT 0,
  gateway_reference VARCHAR(100) NULL,
  created_by     BIGINT UNSIGNED NOT NULL,
  created_at     DATETIME(3) NOT NULL
);

CREATE TABLE {p}shipping_zones (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100) NOT NULL,              -- "Lagos Island", "South West", "Rest of Nigeria"
  sort_order  INT NOT NULL DEFAULT 0              -- first matching zone wins
);

CREATE TABLE {p}shipping_zone_areas (
  zone_id   BIGINT UNSIGNED NOT NULL,
  state     VARCHAR(50) NOT NULL,
  lga       VARCHAR(100) NULL,                    -- NULL = whole state
  KEY zone (zone_id),
  KEY area (state, lga)
);

CREATE TABLE {p}shipping_methods (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  zone_id     BIGINT UNSIGNED NULL,               -- NULL for pickup methods
  name        VARCHAR(100) NOT NULL,              -- "Same-day dispatch rider", "Park waybill", "Pickup"
  type        ENUM('flat','weight_band','free_over','pickup','quote_later') NOT NULL,
  config      JSON NOT NULL,                      -- see section 13.3
  group_ids   JSON NULL,                          -- restrict to customer groups; NULL = all
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  sort_order  INT NOT NULL DEFAULT 0
);

CREATE TABLE {p}shipments (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid            CHAR(36) NOT NULL UNIQUE,
  order_id        BIGINT UNSIGNED NOT NULL,
  status          ENUM('pending','packed','ready_for_pickup','dispatched','delivered',
                       'failed_delivery','returned','cancelled') NOT NULL DEFAULT 'pending',
  method_name     VARCHAR(100) NULL,
  carrier         VARCHAR(100) NULL,               -- courier company, park name, bus line
  waybill_number  VARCHAR(100) NULL,
  rider_name      VARCHAR(100) NULL,
  rider_phone     VARCHAR(20) NULL,
  photo_attachment_id BIGINT UNSIGNED NULL,        -- photo of parcel or waybill slip
  location_id     BIGINT UNSIGNED NOT NULL,        -- shipped from
  packed_at       DATETIME(3) NULL,
  dispatched_at   DATETIME(3) NULL,
  delivered_at    DATETIME(3) NULL,
  created_at      DATETIME(3) NOT NULL,
  updated_at      DATETIME(3) NOT NULL,
  KEY order_ref (order_id),
  KEY status (status)
);

CREATE TABLE {p}shipment_items (
  shipment_id    BIGINT UNSIGNED NOT NULL,
  order_item_id  BIGINT UNSIGNED NOT NULL,
  qty_pieces     INT UNSIGNED NOT NULL,
  PRIMARY KEY (shipment_id, order_item_id)
);

CREATE TABLE {p}shipment_events (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_id  BIGINT UNSIGNED NOT NULL,
  status       VARCHAR(32) NOT NULL,
  note         VARCHAR(255) NULL,
  actor_type   ENUM('staff','system','customer') NOT NULL,
  actor_id     BIGINT UNSIGNED NULL,
  notified     TINYINT(1) NOT NULL DEFAULT 0,
  created_at   DATETIME(3) NOT NULL,
  KEY shipment (shipment_id, id)
);
```

### 8.5 Devices, sync, messaging, system

```sql
CREATE TABLE {p}devices (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid          CHAR(36) NOT NULL UNIQUE,
  name          VARCHAR(100) NOT NULL,            -- "Counter phone 1"
  location_id   BIGINT UNSIGNED NOT NULL,
  token_hash    CHAR(64) NOT NULL UNIQUE,
  status        ENUM('active','revoked') NOT NULL DEFAULT 'active',
  app_version   VARCHAR(20) NULL,
  last_sync_at  DATETIME(3) NULL,
  last_seen_at  DATETIME(3) NULL,
  registered_by BIGINT UNSIGNED NOT NULL,
  created_at    DATETIME(3) NOT NULL
);

CREATE TABLE {p}sync_batches (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id   BIGINT UNSIGNED NOT NULL,
  batch_uuid  CHAR(36) NOT NULL UNIQUE,
  item_count  INT UNSIGNED NOT NULL,
  result      JSON NOT NULL,                      -- per-item outcome returned to the device
  received_at DATETIME(3) NOT NULL
);

CREATE TABLE {p}idempotency_keys (
  idem_key     VARCHAR(64) NOT NULL,
  scope        VARCHAR(64) NOT NULL,              -- endpoint or operation name
  actor_key    VARCHAR(64) NOT NULL,              -- user/device/customer identifier
  request_hash CHAR(64) NOT NULL,                 -- SHA-256 of the canonical request body
  status_code  SMALLINT NOT NULL,
  response     JSON NOT NULL,
  created_at   DATETIME(3) NOT NULL,
  PRIMARY KEY (scope, actor_key, idem_key),
  KEY created (created_at)                        -- purged after 7 days
);

CREATE TABLE {p}message_templates (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event        VARCHAR(64) NOT NULL,              -- 'order.created', 'shipment.dispatched', ...
  channel      ENUM('whatsapp_api','sms','email','tap_to_send') NOT NULL,
  provider_template_name VARCHAR(100) NULL,       -- approved WhatsApp template name
  language     VARCHAR(10) NOT NULL DEFAULT 'en',
  body         TEXT NOT NULL,                     -- with {{placeholders}}
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY one (event, channel, language)
);

CREATE TABLE {p}message_log (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event               VARCHAR(64) NOT NULL,
  channel             VARCHAR(20) NOT NULL,
  category            ENUM('utility','marketing','authentication','service','none') NOT NULL,
  recipient           VARCHAR(191) NOT NULL,
  order_id            BIGINT UNSIGNED NULL,
  customer_id         BIGINT UNSIGNED NULL,
  status              ENUM('queued','sent','delivered','read','failed') NOT NULL,
  provider            VARCHAR(50) NULL,
  provider_message_id VARCHAR(191) NULL,
  cost_micro_usd      INT NULL,                   -- estimated provider cost in millionths of a USD
  cost_kobo           BIGINT NULL,                -- for SMS billed in naira
  error               VARCHAR(255) NULL,
  created_at          DATETIME(3) NOT NULL,
  updated_at          DATETIME(3) NOT NULL,
  KEY created (created_at),
  KEY order_ref (order_id)
);

CREATE TABLE {p}alerts (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type         ENUM('oversell','low_stock','ledger_mismatch','device_stale',
                    'payment_mismatch','webhook_failure','import_errors') NOT NULL,
  severity     ENUM('info','warning','critical') NOT NULL,
  subject_type VARCHAR(32) NULL,
  subject_id   BIGINT UNSIGNED NULL,
  payload      JSON NOT NULL,
  status       ENUM('open','acknowledged','resolved') NOT NULL DEFAULT 'open',
  resolved_by  BIGINT UNSIGNED NULL,
  resolution   VARCHAR(255) NULL,
  created_at   DATETIME(3) NOT NULL,
  resolved_at  DATETIME(3) NULL,
  KEY open_alerts (status, type)
);

CREATE TABLE {p}audit_log (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_type   ENUM('staff','owner_impersonation','system','device','customer') NOT NULL,
  actor_id     BIGINT UNSIGNED NULL,
  action       VARCHAR(64) NOT NULL,              -- 'price.updated', 'stock.adjusted', 'order.cancelled'
  entity_type  VARCHAR(32) NOT NULL,
  entity_id    BIGINT UNSIGNED NULL,
  before_data  JSON NULL,
  after_data   JSON NULL,
  ip           VARCHAR(45) NULL,
  created_at   DATETIME(3) NOT NULL,
  KEY entity (entity_type, entity_id),
  KEY created (created_at)
);

CREATE TABLE {p}imports (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid         CHAR(36) NOT NULL UNIQUE,
  kind         ENUM('products','stock','prices','customers','bumpa') NOT NULL,
  attachment_id BIGINT UNSIGNED NOT NULL,
  mapping      JSON NULL,
  status       ENUM('uploaded','mapped','validating','ready','importing','done','failed') NOT NULL,
  rows_total   INT NULL,
  rows_ok      INT NULL,
  rows_failed  INT NULL,
  errors       JSON NULL,                         -- row number + message, downloadable
  created_by   BIGINT UNSIGNED NOT NULL,
  created_at   DATETIME(3) NOT NULL,
  updated_at   DATETIME(3) NOT NULL
);

CREATE TABLE {p}sequences (                        -- gap-tolerant counters, e.g. order numbers
  name   VARCHAR(32) PRIMARY KEY,
  value  BIGINT UNSIGNED NOT NULL
);
```

Store settings (bank details, hold durations, discount limits, store mode, low-stock default, timezone, order prefix, notification routing, gateway keys) are stored as WordPress options prefixed `wsp_`. Secret keys MUST be encrypted at rest (section 19.4).

### 8.6 Network (platform) tables

```sql
CREATE TABLE {n}plans (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(32) NOT NULL UNIQUE,     -- 'starter','growth','pro'
  name            VARCHAR(100) NOT NULL,
  monthly_kobo    BIGINT NOT NULL,                 -- TBD-OWNER
  setup_fee_kobo  BIGINT NOT NULL,                 -- TBD-OWNER
  annual_kobo     BIGINT NULL,                     -- optional annual price
  limits          JSON NOT NULL,                   -- see 17.3
  features        JSON NOT NULL,                   -- feature flags, see 17.3
  paystack_plan_code VARCHAR(64) NULL,
  is_public       TINYINT(1) NOT NULL DEFAULT 0,   -- shown on the pricing page
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      DATETIME(3) NOT NULL
);

CREATE TABLE {n}applications (                     -- merchants applying for the service
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid           CHAR(36) NOT NULL UNIQUE,
  business_name  VARCHAR(191) NOT NULL,
  contact_name   VARCHAR(191) NOT NULL,
  phone_e164     VARCHAR(20) NOT NULL,
  email          VARCHAR(191) NULL,
  category       VARCHAR(100) NULL,                -- free text: what they sell
  sells_to       ENUM('wholesale','retail','both') NOT NULL,
  approx_products INT NULL,
  current_tools  VARCHAR(255) NULL,                -- e.g. "Bumpa, WhatsApp"
  instagram      VARCHAR(100) NULL,
  message        TEXT NULL,
  status         ENUM('new','contacted','approved','rejected','converted') NOT NULL DEFAULT 'new',
  owner_notes    TEXT NULL,
  created_at     DATETIME(3) NOT NULL,
  updated_at     DATETIME(3) NOT NULL
);

CREATE TABLE {n}tenants (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid             CHAR(36) NOT NULL UNIQUE,
  blog_id          BIGINT UNSIGNED NOT NULL UNIQUE,
  application_id   BIGINT UNSIGNED NULL,
  business_name    VARCHAR(191) NOT NULL,
  owner_user_id    BIGINT UNSIGNED NOT NULL,       -- merchant owner's WP user
  plan_id          BIGINT UNSIGNED NOT NULL,
  status           ENUM('provisioning','trial','active','past_due','read_only','suspended','cancelled') NOT NULL,
  store_mode       ENUM('retail','wholesale','both') NOT NULL DEFAULT 'both',
  primary_domain   VARCHAR(191) NULL,
  trial_ends_at    DATETIME(3) NULL,
  grace_ends_at    DATETIME(3) NULL,
  created_at       DATETIME(3) NOT NULL,
  updated_at       DATETIME(3) NOT NULL,
  KEY status (status)
);

CREATE TABLE {n}subscriptions (
  id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id              BIGINT UNSIGNED NOT NULL,
  plan_id                BIGINT UNSIGNED NOT NULL,
  provider               ENUM('paystack','manual') NOT NULL DEFAULT 'paystack',
  provider_customer_code VARCHAR(64) NULL,
  provider_subscription_code VARCHAR(64) NULL,
  provider_email_token   VARCHAR(64) NULL,
  status                 ENUM('active','non_renewing','attention','cancelled','completed') NOT NULL,
  current_period_start   DATETIME(3) NULL,
  current_period_end     DATETIME(3) NULL,
  next_payment_at        DATETIME(3) NULL,
  created_at             DATETIME(3) NOT NULL,
  updated_at             DATETIME(3) NOT NULL,
  KEY tenant (tenant_id)
);

CREATE TABLE {n}platform_invoices (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid              CHAR(36) NOT NULL UNIQUE,
  tenant_id         BIGINT UNSIGNED NOT NULL,
  kind              ENUM('setup','subscription','addon','service','message_overage') NOT NULL,
  description       VARCHAR(255) NOT NULL,
  amount_kobo       BIGINT NOT NULL,
  status            ENUM('draft','open','paid','void') NOT NULL,
  provider_reference VARCHAR(100) NULL UNIQUE,
  due_at            DATETIME(3) NULL,
  paid_at           DATETIME(3) NULL,
  created_at        DATETIME(3) NOT NULL,
  KEY tenant (tenant_id, status)
);

CREATE TABLE {n}usage_counters (
  tenant_id   BIGINT UNSIGNED NOT NULL,
  metric      VARCHAR(32) NOT NULL,                -- 'variants','staff','locations','devices','orders','wa_messages'
  period      CHAR(7) NOT NULL,                    -- 'YYYY-MM'; 'current' for gauges
  value       BIGINT NOT NULL,
  updated_at  DATETIME(3) NOT NULL,
  PRIMARY KEY (tenant_id, metric, period)
);

CREATE TABLE {n}service_requests (                 -- retainer work requested by merchants
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id    BIGINT UNSIGNED NOT NULL,
  title        VARCHAR(191) NOT NULL,
  details      TEXT NULL,
  status       ENUM('open','in_progress','done','declined') NOT NULL DEFAULT 'open',
  created_by   BIGINT UNSIGNED NOT NULL,
  created_at   DATETIME(3) NOT NULL,
  updated_at   DATETIME(3) NOT NULL
);

CREATE TABLE {n}platform_audit_log (               -- Owner actions, impersonation, plan changes
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id    BIGINT UNSIGNED NOT NULL,
  tenant_id   BIGINT UNSIGNED NULL,
  action      VARCHAR(64) NOT NULL,
  details     JSON NULL,
  ip          VARCHAR(45) NULL,
  created_at  DATETIME(3) NOT NULL
);
```

---

## 9. The stock ledger (core engine)

This is the most important part of the system. If the ledger is right, R2 is solved and every other screen can trust stock numbers.

### 9.1 Three numbers per variant per location

- **on_hand** — units physically at the location.
- **reserved** — units promised to open orders not yet handed over.
- **available = on_hand − reserved** — the only number shown to buyers and used to accept new orders.

Storefront availability for a variant = sum of `available` over locations where `sellable_online = 1`, minus any active safety buffer (9.5).

### 9.2 Movement types and their effects

All quantities are in base units (pieces).

| Type | Trigger | on_hand_delta | reserved_delta |
|---|---|---|---|
| `opening` | First stock entry when a variant is created or imported | +q | 0 |
| `receive` | Stock received from a supplier (restock screen, receipt) | +q | 0 |
| `reserve` | An order line is created or its quantity increased | 0 | +q |
| `release` | Order cancelled, line reduced, or hold expired | 0 | −q |
| `commit` | Reserved goods leave: shipment marked packed/dispatched, pickup collected, or quick-order "handed over now" | −q | −q |
| `counter_sale` | POS sale (goods leave immediately, never reserved) | −q | 0 |
| `return` | Customer returns goods and staff choose "restock" | +q | 0 |
| `transfer_out` | Stock transfer sent | −q | 0 |
| `transfer_in` | Stock transfer received | +q | 0 |
| `adjust` | Stock count difference, damage, loss, found stock, correction (reason required) | ±q | 0 |

**When is stock committed?** Store setting `commit_on` with values `packed` (default) or `dispatched`. Payment alone never moves on_hand: a paid order whose goods are still on the shelf is still reserved.

### 9.3 The write rule

Every stock change is done by `InventoryService` inside one database transaction that:

1. Locks or conditionally updates the `stock_levels` row.
2. Inserts the `stock_movements` row.
3. Updates `stock_levels.last_movement_id` and `updated_at`.
4. Commits. Hooks fire after commit (`wsp/stock/moved`), never inside the transaction.

If the `stock_levels` row does not exist, create it with `INSERT … ON DUPLICATE KEY UPDATE` inside the same transaction.

### 9.4 Reservation without overselling (web, quick order)

Reservation uses a single conditional statement so two buyers can never both get the last unit:

```sql
UPDATE {p}stock_levels
SET reserved = reserved + :qty, updated_at = :now
WHERE variant_id = :variant AND location_id = :location
  AND on_hand - reserved >= :qty;
-- affected rows = 1 → success; 0 → not enough available stock at this location
```

Pseudocode for reserving a whole order (lines may need different locations):

```
function reserveOrder(order, lines, allowedLocations):
  begin transaction
  for line in lines sorted by (variant_id) asc:         # fixed lock order prevents deadlocks
    remaining = line.qty_pieces
    for loc in allowedLocations ordered by online_priority:
      take = min(remaining, available(line.variant, loc))   # read with SELECT ... FOR UPDATE
      if take > 0:
        conditional UPDATE reserved += take (as above); if 0 rows → re-read and retry once
        insert movement(type=reserve, reserved_delta=+take, order_id, order_item_id, location=loc)
        remaining -= take
      if remaining == 0: break
    if remaining > 0 and not variant.allow_backorder:
      rollback; throw InsufficientStock(variant, requested, available_total)
  commit
```

v1 simplification allowed: if splitting a line across locations is not needed by the pilot, reserve each line from a single location (first location with enough stock) and keep the interface ready for splitting. Record the choice in `DECISIONS.md`.

### 9.5 Holds and expiry

| Order source | Default hold | Setting |
|---|---|---|
| Web checkout, card/gateway payment pending | 30 minutes | `hold_minutes_gateway` |
| Web checkout, bank transfer chosen | 24 hours | `hold_minutes_transfer` |
| Quick order (staff) unpaid | No expiry (staff cancel manually) | `hold_minutes_staff` (default null) |
| Pay on delivery | No expiry | — |
| Virtual account payment pending | 24 hours | `hold_minutes_virtual_account` |

A background job runs every minute: find open, unpaid orders with `hold_expires_at < now`, cancel them with reason `hold_expired`, release reservations, notify the customer (optional template), and log. A payment arriving after expiry (late webhook) MUST be handled: try to re-reserve; if stock is gone, mark the order `paid` + raise a `payment_mismatch` alert for staff to refund or restock.

### 9.6 Ledger integrity

- `stock_levels` is a cache of the ledger. For any variant/location: `on_hand = SUM(on_hand_delta)` and `reserved = SUM(reserved_delta)` over `stock_movements`.
- A nightly job (and a WP-CLI command `wp wsp stock verify [--fix]`) recomputes these sums and compares. Any difference raises a critical `ledger_mismatch` alert. `--fix` rewrites `stock_levels` from the ledger, never the other way round.
- Reserved MUST equal the sum of unfulfilled quantities of open orders reserved at that location; the verify job checks this too.

### 9.7 Stock counts

1. Staff start a count for a location (optionally a category/shelf).
2. They scan or search and type counted quantities. `expected_qty` is snapshotted at the moment each line is counted (so sales during counting don't create false differences).
3. Submit → manager reviews differences → apply → one `adjust` movement per difference with `reason = count`, `count_id` set.

### 9.8 Pack units and the ledger

The ledger always counts pieces. An order line of "2 Dozen" stores `qty = 2`, `pack_pieces = 12`, `qty_pieces = 24` and reserves 24. Display may show stock in a chosen pack ("8 dozen and 4 pieces").

### 9.9 Oversell (offline only)

Only the offline POS sync path (section 15) may push `on_hand` below `reserved` or below zero, because the goods already left the shop. When that happens:

- The movement is accepted.
- A critical `oversell` alert is raised listing the variant, location, the shortfall, and the open orders whose reservations are now not backed by stock (newest first).
- Staff resolve by receiving stock, transferring stock, changing an order's items, or cancelling/refunding an order. Resolution is recorded on the alert.

### 9.10 Low stock

When available (across sellable locations) drops to or below the variant's threshold (or store default), raise a `low_stock` alert (deduplicated: one open alert per variant) and include it in the merchant's daily summary message.

---

## 10. Pricing

### 10.1 Price resolution algorithm

Input: variant, pack unit (or piece), quantity in that unit, customer (or guest), at time T.

```
function resolvePrice(variant, pack, qty, customer, T):
  group = customer?.approval_status == 'approved' ? customer.group : defaultRetailGroup
  # 1. Customer-specific price
  p = customer_prices where customer, variant, pack, valid at T
  if p: return (p.amount, 'customer')
  # 2. Group price list, best tier whose min_qty <= qty
  if group.price_list is not base:
     tiers = prices where list=group.price_list, variant, pack order by min_qty desc
     if group.ignore_item_minimums:
        t = tiers with min_qty <= qty, highest min_qty; if none, take the tier with the LOWEST min_qty
     else:
        t = tiers with min_qty <= qty, highest min_qty
     if t: return (t.amount, t.min_qty > 1 ? 'group_tier' : 'group')
  # 3. Base (retail) list with quantity tiers — open to everyone, including guests
  t = base prices for variant, pack with min_qty <= qty, highest min_qty
  if t: return (t.amount, t.min_qty > 1 ? 'base_tier' : 'base')
  # 4. Pack with no price set: derive from piece price × pieces (only if store setting allows)
  if pack and setting.derive_pack_prices: return resolvePrice(variant, piece, qty*pack.pieces, customer, T) per piece × pack.pieces
  throw PriceNotFound
```

**This is how R1 is solved:** an approved wholesaler buying 3 pieces gets the wholesale price even if the wholesale list's lowest tier is "12 or more", because `ignore_item_minimums = 1` makes them fall back to the lowest wholesale tier instead of to retail.

### 10.2 What guests and retail customers see

- Retail price, plus any base-list quantity tiers shown as "12+ pieces: ₦x each" (lets walk-in bulk buyers convert).
- If the store is in `both` mode, a line "Are you a reseller? Register to see wholesale prices" with a link.
- Wholesale prices are never sent to the browser for non-wholesale sessions (not just hidden with CSS).

### 10.3 Group minimum order value

If `customer_groups.min_order_value_kobo` is set, checkout and quick order show a warning when the order subtotal is below it. Storefront checkout blocks the order; staff can override in quick order (with permission).

### 10.4 Manual prices and discounts

- Staff with `wsp_override_price` can set a manual line price (`price_source = manual`), audit-logged.
- Order-level discounts: amount or percentage, within the staff member's discount limit.
- Coupons are out of scope for v1 (design the order totals to allow adding them).

### 10.5 Price changes

- Bulk price edit by percentage or fixed amount per category/group, with preview before apply.
- Every price change is written to the audit log with before/after.
- Existing orders never change (line snapshots).

---

## 11. Feature specifications

### 11.1 Customer registration and sign-in (R1, R5)

- Sign-up asks: phone number, name, and **"How are you buying?"** with choices from self-registerable groups (default: "For myself (retail)" and "To resell (wholesale)").
- Choosing wholesale asks for business name (required), state/LGA, and optionally Instagram handle.
- Phone is verified with a 6-digit OTP sent by WhatsApp authentication template, falling back to SMS. Codes expire in 10 minutes, max 5 attempts, max 5 codes per phone per hour, max 20 per IP per hour.
- **Approval:** if the chosen group has `approval = auto` (the Owner's default for wholesale), the customer is approved immediately and sees wholesale prices at once. If `manual`, they are placed in the retail group with `requested_group_id` set and `approval_status = pending`; staff approve or reject from a queue, and the customer is notified.
- Staff can create customers from quick order/POS with just phone + name (no OTP), assign a group, and the customer can later sign in with OTP using the same phone.
- Session: random 32-byte token in an HTTP-only, Secure, SameSite=Lax cookie; 90-day sliding expiry; "sign out of all devices" supported.
- A store in `retail` mode hides the wholesale choice entirely. A store in `wholesale` mode hides retail sign-up and can require sign-in to see prices (setting).

### 11.2 Quick order — the WhatsApp order desk (R2)

Goal: a staff member reading a WhatsApp chat can record the order in under 30 seconds on a phone.

Flow:
1. **Customer:** search by phone or name, or paste a phone number from WhatsApp (auto-normalised). "New customer" needs only phone + name; group defaults to Retail, switchable.
2. **Items:** search by name/SKU/barcode (camera scan supported). Tapping a product opens its option grid (e.g. colours × sizes) showing available stock in each cell; type quantities directly into cells. Pack unit selector per line. Prices resolve automatically for the customer's group.
3. **Channel:** WhatsApp (default), Instagram, Phone, Walk-in, Other.
4. **Delivery:** pickup or delivery; pick saved address or type a new one (state + LGA required); shipping fee computed from zones, editable with permission.
5. **Payment** (one of):
   - "Customer will pay by bank transfer" → order saved unpaid with hold; customer gets bank details.
   - "Send payment link" → gateway link generated (Paystack/Flutterwave/third gateway as configured).
   - "Already paid by transfer" → staff confirm immediately (proof optional, sender name optional).
   - "Cash / card terminal" (walk-in).
   - "Pay on delivery" (if allowed for the group).
6. **Save** → stock reserved instantly (no gateway involved, no gateway fee). Order number shown.
7. **Send to customer:** buttons "Send on WhatsApp" (tap-to-send, opens the merchant's WhatsApp with a pre-filled summary: items, total, bank details, tracking link) and/or automatic WhatsApp API message if enabled.

Also:
- "Handed over now" toggle for walk-in sales from quick order: commits stock immediately (no shipment).
- Draft orders: save without reserving (status `draft`) while the customer decides; drafts do not hold stock and show a warning if stock changes.
- Duplicate-order guard: same customer, same items within 10 minutes → confirm dialog.
- Reorder: from a customer's history, "repeat order" pre-fills the last order.

### 11.3 Product entry and bulk tools (R3)

**Quick add (phone):** photos (camera or gallery, multiple), name, price, quantity. Category optional. SKU auto-generated (`{CATEGORY_CODE}-{6 digits}` or a store-defined pattern). Product goes live immediately if the user has publish rights, else stays draft.

**Options and variant grid:**
- Pick options (Colour, Size, …) from the store's list or create new ones inline.
- Tick values → a grid of all combinations appears. Type stock and (optionally) price per cell, or "apply to all/row/column".
- Mark which option drives photos (default: the first `color`/`image` option).
- Assign photos to option values by dragging photos onto value chips (or choosing a value when uploading).

**Bulk photo upload:** drop/choose up to 200 photos → one draft product per photo (or group photos by filename prefix, e.g. `ring-12_red.jpg`, `ring-12_blue.jpg` → one product "ring-12" with Colour values Red, Blue). Then a grid to fill names, prices and quantities, and publish selected.

**Spreadsheet import (CSV/XLSX):**
- Template download with columns: `product_name, sku, category, description, option1_name, option1_value, option2_name, option2_value, option3_name, option3_value, retail_price, wholesale_price, wholesale_min_qty, cost_price, quantity, location, barcode, weight_grams, image_urls, status`.
- One row per variant; rows with the same `product_name` (or a `product_handle` column) form one product.
- Upload → column mapping screen (auto-matched by header) → validation preview showing every error by row → import runs in the background in chunks of 200 rows → result summary + downloadable error report.
- Nothing is written until validation passes, unless the user chooses "import valid rows only".
- Re-import by SKU updates existing variants (prices, stock via `adjust` movements with reason `import`, never by overwriting stock_levels).
- Images from URLs are fetched in background jobs into the media library.

**Bumpa import:** accept Bumpa's product export file. The exact column format is unknown — `OPEN QUESTION: obtain a sample Bumpa export from the pilot merchant` — build it as a preset mapping on top of the generic importer.

**Bulk edit:** spreadsheet-style table (virtualised for 10,000+ rows) to edit name, category, price per list, status, threshold. Stock is not editable here (stock changes go through receive/adjust so the ledger stays honest); instead a "Set stock to…" action creates `adjust` movements with a reason.

**Restock screen:** pick location → scan/search → type quantity received → optional supplier and cost → save as a receipt with `receive` movements.

**Uploader role** can do all of the above except change prices (price fields hidden) and see revenue.

### 11.4 Option swatches and photos on the storefront (R4)

- Product page shows options as swatches according to `options.display`: colour chips (from `color_hex`), image chips (from `option_values.image_id` or a crop of the first photo for that value), labels, or a dropdown.
- **Selecting a value of the media option replaces the gallery** with that value's photos (followed by general photos). All photos for all values are included in the initial page payload so switching is instant (no network request); images lazy-load with proper `srcset`.
- Unavailable combinations are shown greyed/struck through, not hidden. Selecting one shows "Out of stock" and an optional "Notify me" (out of scope v1; leave hook).
- The URL updates with `?colour=rose-gold` (option slug = value slug) so a shared link opens on that value. Server rendering honours the query string (correct photo in link previews: Open Graph image = first photo of the selected value).
- Shop/category pages: setting to show each media-option value as its own card ("Earrings – Gold", "Earrings – Silver") or one card per product with small swatches that also switch the card image.
- Price shown updates with the selected variant and the customer's group.

### 11.5 Wholesale and retail in one system (R5)

Two meanings, both covered:
- **For buyers:** one storefront; the same product shows different prices, tiers, minimums and payment options depending on the signed-in customer's group.
- **For merchants:** `tenants.store_mode` = `retail`, `wholesale` or `both`, chosen at provisioning and changeable by the Owner. `retail` hides all group/price-list UI except the base list; `wholesale` makes the wholesale list the main list and can require sign-in to see prices. Plans may restrict modes (17.3).

### 11.6 Delivery tracking (R6) — see section 14.

### 11.7 Back office dashboard

Phone-first home screen showing: today's sales by channel, orders waiting for payment confirmation, orders to pack, parcels in transit, open alerts (oversell first), low-stock count, and quick actions (New order, Add product, Receive stock, Scan).

### 11.8 Orders list

One list for all channels with filters (channel, payment status, fulfilment status, date, customer group, staff, location), search (order number, phone, name), bulk actions (mark packed, mark dispatched, print packing slips, export CSV). Saved views: "To confirm payment", "To pack", "In transit", "Pickup ready".

### 11.9 Customers

List with search and group filter; profile shows orders, total spent, addresses, group, approval status, notes, special prices; actions: change group, approve/reject, set special price, start order.

### 11.10 Reports (v1)

- Sales by day/week/month, by channel, by staff, by customer group, by location.
- Top products and top customers.
- Stock on hand and stock value (at cost) by location.
- Low stock and out of stock.
- Daily cash-up per POS device/cashier: cash, transfer, terminal totals vs recorded.
- Payments awaiting confirmation.
- Message usage and estimated cost.
- All reports exportable to CSV.

### 11.11 Printing

- Packing slip and invoice/receipt as printable HTML (A4 and 80 mm thermal receipt widths), with store logo, order items with option values and photos (A4 only), totals, payment status, and QR code of the tracking link.
- Shareable receipt image/PDF for sending on WhatsApp.

---

## 12. Orders and state machines

An order has **three independent statuses**: overall `status`, `payment_status`, `fulfilment_status`. Shipments have their own status (section 14). Keeping them separate is what lets a WhatsApp order be "unpaid but packed", or "paid but not yet sent".

### 12.1 Overall status

| From | To | Trigger | Side effects |
|---|---|---|---|
| (new) | `draft` | Staff save as draft | No stock reserved |
| (new) / `draft` | `open` | Order placed (any channel) | Reserve stock (except counter sales, which commit directly); set hold expiry; notify |
| `open` | `completed` | Fully paid (or pay on delivery collected) AND fully fulfilled, or fulfilment not required (walk-in handed over) | `completed_at`; customer totals updated |
| `open` | `cancelled` | Staff cancel, customer cancels unpaid web order, or hold expired | Release all un-committed reservations; refund flow if anything was paid |
| `completed` | (none) | Returns are handled by refunds/returns, not by reopening | — |

Invalid transitions throw `InvalidTransition`. All transitions go through `OrderService` and are audit-logged.

### 12.2 Payment status

Computed from payment records, never set by hand:

- `unpaid`: no succeeded payments, no pending proof.
- `awaiting_confirmation`: a manual transfer proof was uploaded and is waiting for staff.
- `partially_paid`: 0 < paid < total.
- `paid`: paid ≥ total.
- `pay_on_delivery`: POD chosen and nothing collected yet.
- `partially_refunded` / `refunded`: after refunds.

Overpayment (paid > total) raises a `payment_mismatch` alert.

### 12.3 Fulfilment status

Computed from shipments and line `qty_fulfilled_pieces`:
- `unfulfilled` → `partially_fulfilled` → `fulfilled`; `returned` when all fulfilled items came back.

### 12.4 Editing open orders

- Staff can add/remove lines or change quantities while nothing on that line is fulfilled. Changes reserve/release the difference in the same transaction and recalc totals.
- If the order is already paid and the total changes, show the difference: "Customer owes ₦x" or "Refund ₦x".
- Every edit is audit-logged with before/after.

### 12.5 Order numbers

Store prefix (2–4 letters, setting) + sequence from `{p}sequences` incremented in a transaction (`UPDATE … SET value = LAST_INSERT_ID(value + 1)`). Sequences may have gaps; numbers never repeat.

### 12.6 Hooks fired

`wsp/order/created`, `wsp/order/updated`, `wsp/order/cancelled`, `wsp/order/completed`, `wsp/order/payment_status_changed`, `wsp/order/fulfilment_status_changed`, `wsp/payment/succeeded`, `wsp/payment/failed`, `wsp/refund/created`, `wsp/shipment/status_changed`, `wsp/stock/moved`, `wsp/stock/low`, `wsp/stock/oversold`, `wsp/customer/registered`, `wsp/customer/approved`. All fire after the database transaction commits.

---

## 13. Payments and shipping

### 13.1 Payment methods

| Method | Fee to merchant | Flow |
|---|---|---|
| Direct bank transfer | None | Customer sees the merchant's account details (bank, number, name) and the exact amount + order number as narration. Customer uploads proof (image/PDF) and sender name on the order page or sends it on WhatsApp. Staff confirm → payment `succeeded`, `confirmed_by` recorded. |
| Paystack dedicated virtual account | 1% capped at NGN 300 (Paystack Nigeria, Sept 2026) | Each customer (or each order, setting) gets a Paystack dedicated account number. Paystack webhook confirms automatically. Best for wholesalers paying large amounts by transfer. |
| Paystack checkout (card, bank transfer, USSD, bank app) | 1.5% + NGN 100, capped at NGN 2,000; NGN 100 waived under NGN 2,500 (Paystack Nigeria, Sept 2026) | Web checkout redirect/popup and payment links from quick order. Verify server-side. |
| Flutterwave checkout | Roughly 1.4% local per public 2026 comparisons (verify at integration time) | Same as Paystack checkout via its adapter. |
| Third gateway ("GoPay", provider TBD) | TBD | Adapter slot. Do not implement until the Owner confirms the provider. |
| Cash | None | POS / quick order walk-in. |
| Card terminal | Terminal provider's fee | POS records amount + optional reference; no integration in v1. |
| Pay on delivery | None | Allowed per customer group and shipping method. Rider/dispatcher marks collected (cash or transfer) at delivery. |

Fee pass-through: store setting per gateway: absorb fees (default) or add a fee line to the order total. Compute the fee with the gateway's published formula so the merchant nets the item total (Paystack documents a formula for this; implement it inside the Paystack adapter).

Each merchant connects **their own** gateway accounts (their keys). WSP never receives customer money. The Owner's own Paystack account is used only for platform billing (section 17).

### 13.2 Payment gateway adapter interface

```php
namespace WSP\Commerce\Payments;

interface GatewayAdapter {
    public function id(): string;                         // 'paystack', 'flutterwave', 'gateway3'
    public function label(): string;
    public function isConfigured(): bool;
    public function supports(string $feature): bool;      // 'checkout','payment_link','virtual_account','refund','fee_passthrough'
    public function feeFor(Money $amount, string $channel = 'local'): Money;
    public function initialize(PaymentIntent $intent): InitializeResult;   // returns redirect/authorization URL or inline config
    public function verify(string $reference): VerifyResult;               // server-side verification by reference
    public function handleWebhook(WP_REST_Request $request): WebhookResult;// MUST verify signature first
    public function createVirtualAccount(Customer $c): ?VirtualAccount;    // null if unsupported
    public function refund(Payment $p, Money $amount, string $reason): RefundResult;
}
```

Rules for every adapter:
- **Never trust the browser.** A payment is `succeeded` only after server-side verification (verify API or a signature-checked webhook) confirms status success, the **amount equals** what we expect in kobo, the **currency** matches, and the **reference** belongs to this order.
- Webhook signature: Paystack sends an `x-paystack-signature` header that is an HMAC SHA-512 of the raw body with the secret key; Flutterwave sends a `verif-hash` header that must equal the merchant's configured secret hash. Check against current provider docs during implementation.
- Webhooks are idempotent: the unique key `(method, gateway_reference)` on `payments` prevents double-crediting. Respond 200 quickly and process heavy work in a job.
- Webhook URL per tenant: `https://{store-domain}/wp-json/wsp/v1/webhooks/{gateway}`. Shown in the merchant's settings for them to paste into their gateway dashboard.
- A reconciliation job every 15 minutes re-verifies `pending` gateway payments older than 5 minutes (in case a webhook was missed).
- Failed signature or unknown reference → 200 response (to stop retries) + `webhook_failure` alert + log; never create payments from unverified data.

### 13.3 Shipping

**Zones:** Ordered list; first zone whose areas match the address (state + LGA, or whole state) wins. Seed data includes all 36 states + FCT and their LGAs (774 LGAs) from `tools/seed/ng-states-lgas.json`. A default "Rest of Nigeria" zone is created at provisioning.

**Methods** (`shipping_methods.config` JSON):

| type | config example | Meaning |
|---|---|---|
| `flat` | `{"amount_kobo": 250000}` | Fixed fee |
| `weight_band` | `{"bands":[{"max_grams":1000,"amount_kobo":200000},{"max_grams":5000,"amount_kobo":350000}],"over_per_kg_kobo":50000}` | By total weight |
| `free_over` | `{"threshold_kobo":10000000,"else_amount_kobo":300000}` | Free above order value |
| `pickup` | `{"location_id": 3, "instructions": "Shop 12, Balogun market"}` | Customer collects |
| `quote_later` | `{"message": "We will send the delivery fee on WhatsApp"}` | Fee set by staff after order (order stays open; customer notified of the fee) |

Methods can be restricted to customer groups (e.g. park waybill only for wholesale). Interstate park/bus delivery is a method like any other; the waybill number and park are recorded on the shipment.

Courier API adapters (`CourierAdapter` interface: `quote`, `book`, `track`, `cancel`) are designed for v2.

---

## 14. Fulfilment and delivery tracking (R6)

### 14.1 Shipment lifecycle

```
pending ──► packed ──► dispatched ──► delivered
   │           │            │
   │           └──► ready_for_pickup ──► delivered (collected)
   │                        └──► failed_delivery ──► dispatched (retry) | returned
   └──► cancelled
```

- **Create shipment:** from an order, choose lines and quantities (defaults to everything unfulfilled). One order can have several shipments.
- **packed:** stock commits here if `commit_on = packed` (default). Packing slip can be printed.
- **dispatched:** requires carrier/method; optional waybill number, rider name and phone, photo of parcel or waybill slip. Commits stock if `commit_on = dispatched`.
- **ready_for_pickup:** for pickup methods.
- **delivered:** optional proof photo; for pay-on-delivery, record the amount collected and how (creates a payment).
- **failed_delivery:** note required; can be re-dispatched or returned.
- **returned:** items come back; staff choose restock (creates `return` movements) or write-off (`adjust`, reason `damaged`).

Each change writes a `shipment_events` row and (per notification settings) messages the customer.

### 14.2 Staff experience

- Orders list "To pack" view with big buttons: **Packed**, **Sent out**, **Delivered**, usable one-handed; bulk select for many orders.
- Dispatch sheet: list today's parcels with rider and destination, printable.

### 14.3 Customer experience

- Every order has a public tracking page: `https://{store}/track/{order.number}?t={public_token}` (token required; no login).
- Shows: order items with photos, payment status (with bank details and upload-proof button if unpaid), shipment timeline with dates, carrier, waybill number, rider phone (tap to call), and delivery photo when available.
- Signed-in customers see all their orders and timelines in "My orders".
- The link is included in every order message.

---

## 15. Point of sale and offline sync

### 15.1 POS app

- Installable PWA at `https://{store}/pos/`. Works on Android phones and tablets (primary) and desktop browsers.
- **Device registration:** a manager opens POS on the device, signs in, names the device, picks its location. Server issues a device token (shown once, stored in IndexedDB); devices are listed and revocable in the back office.
- **Cashier sign-in:** staff PIN (4–6 digits, per user, set in back office) on a registered device, so several cashiers share one device and each sale records who sold it.
- **Sell screen:** search/scan, option grid, pack units, customer lookup (optional; walk-in by default), price by group, discount within limit, payment (cash with change calculation, transfer, terminal, split payments), receipt (print via browser to Bluetooth/USB thermal printer where supported, or share as image/WhatsApp).
- **Also:** hold/park a sale, returns at the counter (with original receipt lookup when online), daily cash-up (opening float, expected vs counted cash).

### 15.2 Offline data on the device

IndexedDB (Dexie) stores:
- Catalog snapshot: products, variants, options, photos (thumbnail URLs cached by the service worker), pack units.
- Prices for all groups, customer list (name, phone, group; limited to customers with recent orders, configurable size), special prices.
- Last known stock levels for the device's location (for display only; never blocks an offline sale).
- Outbox: queued operations (sales, returns, cash-up entries, new customers), each with a client-generated UUID and `client_created_at`.
- Sync cursor (`last_pull_cursor`).

### 15.3 Sync protocol

**Pull (server → device)** — every 60 seconds when online and on reconnect:

```
GET /wsp/v1/sync/pull?cursor={opaque}
Authorization: Device {token}
→ 200 { cursor: "...", catalog_changes: [...], price_changes: [...], stock_levels: [...],
        customer_changes: [...], settings: {...}, full_resync_required: false }
```
Cursor is based on a monotonically increasing change id (a `{p}change_log` table or per-table `updated_at` + id). If the cursor is too old (> 7 days) the server returns `full_resync_required: true`.

**Push (device → server)** — immediately after each sale when online, and on reconnect:

```
POST /wsp/v1/sync/push
Authorization: Device {token}
{ "batch_uuid": "…", "device_time": "2026-11-15T10:04:22.120Z", "operations": [
    { "op": "counter_sale", "client_uuid": "…", "client_created_at": "…", "cashier_user_id": 12,
      "customer_id": null, "lines": [ { "variant_uuid": "…", "pack_unit_id": null, "qty": 2,
      "unit_price_kobo": 450000, "price_source": "base" } ], "discount_kobo": 0,
      "payments": [ { "method": "cash", "amount_kobo": 900000 } ] },
    { "op": "counter_return", ... },
    { "op": "customer_create", ... }
]}
→ 200 { "results": [ { "client_uuid": "…", "status": "applied" | "duplicate" | "rejected",
         "order_number": "DJ-10424", "warnings": ["oversell"], "error": null } ],
        "stock_levels": [ … updated levels for affected variants … ] }
```

Server rules:
1. `batch_uuid` and every `client_uuid` are idempotency keys. Re-sent batches return the stored results (`duplicate`), never double-apply.
2. Operations are applied in `client_created_at` order within a batch, each in its own transaction (one bad op does not block the rest).
3. **A counter sale is always accepted** (the goods are gone). It creates an order (`channel = counter`, `status = completed` if fully paid) and `counter_sale` movements. If this leaves `on_hand < reserved` or `on_hand < 0`, raise an `oversell` alert (9.9).
4. **Prices recorded offline are honoured**, even if prices changed meanwhile. If the offline price differs from the current resolved price by more than a setting threshold, attach a `price_changed_offline` note visible on the order.
5. A sale is `rejected` only for structural errors: unknown variant, revoked device, malformed data. Rejected ops stay in the device outbox, flagged for a manager.
6. Server records both `client_created_at` and server `created_at`; reports use `client_created_at` for the sale date.

### 15.4 Reducing oversells

- **Location pools:** shop-floor stock (location used by POS) can be separate from online stock (an `online_pool` location). Staff transfer between them.
- **Safety buffer:** setting `offline_buffer_units` (default 0). While any active device on a location that is also `sellable_online` has `last_sync_at` older than `device_stale_minutes` (default 10), the storefront shows `available − buffer` for that location.
- **Staleness warning:** POS header shows "Last synced 3 min ago" and turns amber/red when stale; a `device_stale` alert is raised for devices offline during business hours.

### 15.5 What "perfect sync" means (wording for sales and onboarding)

While a device is offline, nobody can know what it sold. The guarantee is: **no sale is ever lost, no stock count is ever silently overwritten, every sale appears everywhere within seconds of the device reconnecting, and every mismatch is shown to a person to settle.** Do not promise more than this in UI copy or sales material.

---

## 16. Notifications

### 16.1 Channels

| Channel | How | Cost | Use |
|---|---|---|---|
| WhatsApp Business Platform (official API) | Meta WhatsApp Cloud API, either directly or through a provider (adapter) using the merchant's own WhatsApp Business number | Per message. From 1 Oct 2026 Meta charges service replies and utility messages even inside the 24-hour window. Nigeria approx. $0.0101 per utility message, approx. $0.062 per marketing message (Yournotify summary of Meta pricing; re-check Meta's rate card at build time). | Automatic order, payment and shipment updates; OTP (authentication template). |
| Tap-to-send WhatsApp | `https://wa.me/{phone}?text={urlencoded}` opened from the staff screen into the merchant's own WhatsApp Business app | Free | Staff-initiated sends; works without API setup. |
| SMS | SMS provider adapter (e.g. Termii or similar Nigerian provider) | Per SMS | Fallback when WhatsApp fails or customer has no WhatsApp; OTP fallback. |
| Email | `wp_mail` via SMTP/transactional provider | Low | Optional; customers without email are normal. |
| Back office | In-app notifications + daily summary | — | Merchant/staff alerts. |

### 16.2 Events and default routing

| Event | Recipient | Default channel | WhatsApp category |
|---|---|---|---|
| `order.created` (unpaid, with bank details/payment link) | Customer | WhatsApp API if enabled, else tap-to-send prompt for staff | utility |
| `payment.confirmed` | Customer | WhatsApp API | utility |
| `shipment.packed` | Customer | Off by default | utility |
| `shipment.dispatched` (with carrier, waybill, rider, tracking link) | Customer | WhatsApp API | utility |
| `shipment.ready_for_pickup` | Customer | WhatsApp API | utility |
| `shipment.delivered` | Customer | WhatsApp API | utility |
| `order.hold_expiring` (1 hour before) | Customer | Off by default | utility |
| `customer.approved` / `customer.rejected` | Customer | WhatsApp API | utility |
| `otp` | Customer/staff | WhatsApp authentication template, SMS fallback | authentication |
| `new_order` / `proof_uploaded` / `oversell` / `low_stock` summary / `wholesale_application` | Merchant staff (configurable numbers) | WhatsApp API or back office only | utility |
| Promotions | Opted-in customers | Off; plan feature | marketing |

Each event × channel is a row in `message_templates`; merchants can edit wording (WhatsApp API templates must be submitted for Meta approval; the system tracks approval state). Placeholders: `{{customer_name}}`, `{{order_number}}`, `{{order_total}}`, `{{items_summary}}`, `{{bank_details}}`, `{{payment_link}}`, `{{tracking_link}}`, `{{carrier}}`, `{{waybill}}`, `{{rider_phone}}`, `{{store_name}}`.

### 16.3 Messaging rules

- All sends are background jobs with retries (3 attempts, exponential backoff); failures fall back to SMS if enabled.
- Every send is logged in `message_log` with estimated cost; the back office shows monthly message counts and estimated cost; the platform counts WhatsApp messages per tenant for plan limits.
- Respect `whatsapp_opt_in` and `marketing_opt_in`. Marketing sends require explicit opt-in.
- Quiet hours setting for non-urgent messages (default 21:00–07:00 Africa/Lagos), except OTP.
- Delivery status webhooks from the provider update `message_log.status`.

---

## 17. Platform layer (the Owner's retainer business)

### 17.1 Merchant journey

1. **Apply:** merchant fills the application form on the main site (business name, contact, phone, what they sell, wholesale/retail/both, approx. products, current tools, Instagram). Saved in `applications`; the Owner gets a WhatsApp/email alert.
2. **Review:** the Owner contacts them, sets status, chooses a plan and store mode, optionally a custom setup fee or discount.
3. **Invoice:** system creates a setup-fee invoice (+ first month) with a Paystack payment link, sent by WhatsApp/email.
4. **Pay → provision:** on verified payment, a provisioning job runs (17.2). Status `provisioning` → `active` (or `trial`).
5. **Onboard:** merchant owner gets a WhatsApp message with login link; a first-run checklist inside the back office: store details, logo, bank details, gateways, delivery zones, staff, first products/import, WhatsApp setup, go live.
6. **Recurring billing:** monthly subscription via Paystack (17.4).
7. **Service:** merchant can raise service requests (product uploads, design changes) from the back office; the Owner tracks them (retainer work).

Self-serve signup (pick plan → pay → auto-provision without review) is a feature flag, off by default.

### 17.2 Provisioning job

Idempotent and resumable (each step checks whether it is already done):
1. Create subsite (`wp_insert_site`) with subdomain `{slug}.{platform-domain}`.
2. Create merchant owner WP user (or attach existing by email/phone), grant `wsp_owner` role on the subsite.
3. Run WSP migrations for the subsite.
4. Seed: default locations ("Main shop"), groups (Retail default + Wholesale with auto approval, per store mode), price lists, options (Colour with `color` display, Size with `label`), shipping zones (states/LGAs + "Rest of Nigeria"), message templates, settings (timezone Africa/Lagos, currency NGN, order prefix from business name).
5. Activate storefront theme with merchant name and placeholder logo.
6. Create `tenants` row, link subscription.
7. Send welcome message. Log every step in `platform_audit_log`.

Custom domain: the Owner (or merchant, if the plan allows) adds a domain; system shows DNS instructions (A/CNAME), verifies DNS, updates the site's domain (WordPress domain mapping), and SSL is issued by the hosting layer (e.g. automatic certificates). Document hosting requirements in `docs/HOSTING.md`.

### 17.3 Plans, limits and features

Plan prices and exact limits are `TBD-OWNER`. Build `plans.limits` and `plans.features` as JSON and enforce them in the engine through a `PlanGate` service. Suggested starting structure (all values configurable):

```json
// limits
{ "variants": 1000, "staff_seats": 3, "locations": 1, "pos_devices": 1,
  "wa_messages_per_month": 1000, "storage_mb": 2048, "custom_domain": false }
// features
{ "store_modes": ["retail","wholesale","both"], "pos_offline": true, "virtual_accounts": true,
  "whatsapp_api": true, "bulk_import": true, "bumpa_import": true, "custom_roles": false,
  "multi_location": false, "reports_advanced": false, "marketing_messages": false,
  "self_serve_domain": false, "remove_platform_branding": false, "priority_support": false }
```

Suggested tiers (names and numbers for the Owner to confirm): **Starter** (1 location, 1 device, ~1,000 variants, 3 staff), **Growth** (2 locations, 3 devices, ~10,000 variants, 10 staff, custom domain), **Pro** (unlimited locations/devices within fair use, ~50,000 variants, custom roles, advanced reports, marketing messages, priority support).

Enforcement rules:
- Hard limits (variants, staff seats, devices, locations) block creation with a clear message and an "Upgrade" prompt that notifies the Owner.
- Soft limits (WhatsApp messages) never block transactional messages: over the limit, messages still send and are billed as overage on the next invoice (`message_overage`) or switched to SMS/tap-to-send, per Owner setting.
- Usage is recorded in `usage_counters` and shown to the merchant and the Owner.

### 17.4 Platform billing

- Uses the **Owner's** Paystack account: Paystack Plans + Subscriptions for monthly billing; one-off transactions for setup fees, add-ons and services.
- Webhooks (`charge.success`, `subscription.create`, `subscription.disable`, `invoice.payment_failed`, etc.) update `subscriptions`, `platform_invoices` and `tenants.status`. Verify signatures exactly as in 13.2.
- Manual billing (`provider = manual`) is supported for merchants paying by transfer; the Owner marks invoices paid.
- **Dunning:** payment failure → status `past_due`, WhatsApp/email reminders on day 0, 3, 6 → after `grace_days` (default 7) → `read_only` (staff can view and fulfil existing orders; no new products; storefront still sells, so the merchant's customers are not punished) → after a further 14 days → `suspended` (storefront shows a neutral "temporarily unavailable" page; data kept) → only the Owner can cancel and delete. **Data is never deleted automatically.** Exports remain available to the merchant on request.

### 17.5 Owner console (network admin)

- Tenants list: plan, status, MRR contribution, created date, last activity, usage vs limits, open alerts count, message cost this month.
- Tenant detail: change plan/mode/status, invoices, subscriptions, service requests, notes, provisioning log, domain.
- **Log in as merchant** (impersonation): requires a reason, opens a session limited to that subsite, shows a banner, and is recorded in both `platform_audit_log` and the store's `audit_log` (`actor_type = owner_impersonation`).
- Applications inbox.
- Revenue: MRR, setup fees, churn, past-due list.
- Platform health: job queue backlog, failed jobs, webhook failures, ledger mismatches across tenants.
- Global announcements to all merchant back offices.
- Release management: run migrations network-wide, feature flags per tenant (for gradual rollout).

### 17.6 Branding

Storefront footer shows "Powered by {Platform name}" unless the plan removes it. Product name is `TBD-OWNER`.

---

## 18. REST API

Namespace `wsp/v1`. JSON only. Conventions:
- Auth: staff — WordPress cookie + `X-WP-Nonce` (back office/quick order) or application passwords for scripts; devices — `Authorization: Device {token}`; customers — WSP session cookie; webhooks — signature only.
- Every POST/PUT/PATCH/DELETE requires header `Idempotency-Key` (UUID). Same key + same body → stored response; same key + different body → `409 idempotency_conflict`.
- Errors: `{ "code": "insufficient_stock", "message": "...", "data": { "status": 409, ... } }` with stable codes.
- Pagination: cursor-based (`?cursor=…&limit=50`, max 200). Responses include `next_cursor`.
- IDs exposed are UUIDs. Money fields are integers in kobo with a `currency` field.
- Every controller checks capability first, then calls a service. No SQL in controllers.

Endpoint list (v1):

| Area | Endpoints |
|---|---|
| Catalog | `GET/POST /products`, `GET/PATCH/DELETE(archive) /products/{id}`, `POST /products/{id}/variants/rebuild`, `GET/PATCH /variants/{id}`, `GET/POST /options`, `POST /options/{id}/values`, `POST /products/{id}/media` (assign to option value, reorder), `GET/POST /categories`, `GET/POST /pack-units`, `POST /products/bulk` (bulk edit), `GET /catalog/search?q=&barcode=` |
| Pricing | `GET/POST /price-lists`, `PUT /price-lists/{id}/prices` (batch upsert), `POST /pricing/bulk-adjust` (preview + apply), `POST /pricing/quote` (resolve prices for cart lines + customer) |
| Inventory | `GET /stock?variant=&location=`, `GET /stock/movements?variant=&location=`, `POST /stock/receipts`, `POST /stock/adjustments`, `GET/POST /stock/transfers`, `POST /stock/transfers/{id}/send`, `POST /stock/transfers/{id}/receive`, `GET/POST /stock/counts`, `PUT /stock/counts/{id}/lines`, `POST /stock/counts/{id}/apply`, `GET/POST /locations` |
| Customers | `GET/POST /customers`, `GET/PATCH /customers/{id}`, `POST /customers/{id}/approve`, `POST /customers/{id}/reject`, `GET/PUT /customers/{id}/prices`, `GET/POST /customer-groups` |
| Storefront auth | `POST /auth/otp/request`, `POST /auth/otp/verify`, `POST /auth/logout`, `GET /me`, `PATCH /me`, `GET/POST /me/addresses`, `GET /me/orders` |
| Cart & checkout (storefront) | `GET/POST/PATCH /cart` (server-side cart keyed by session; validates prices and stock), `POST /checkout/shipping-options`, `POST /checkout` (creates order + reservation + payment intent) |
| Orders | `GET/POST /orders`, `GET/PATCH /orders/{id}`, `POST /orders/{id}/cancel`, `POST /orders/{id}/lines` (edit), `POST /orders/{id}/handover`, `GET /orders/{id}/tap-to-send` (pre-filled WhatsApp link), `GET /orders/{id}/print?format=a4|thermal` |
| Payments | `POST /orders/{id}/payments` (record manual/cash/transfer), `POST /payments/{id}/confirm`, `POST /orders/{id}/payment-link`, `POST /orders/{id}/refunds`, `POST /track/{number}/proof` (customer proof upload with public token) |
| Fulfilment | `POST /orders/{id}/shipments`, `PATCH /shipments/{id}` (status + details), `POST /shipments/bulk-status`, `GET /track/{number}?t=` (public) |
| Shipping | `GET/POST /shipping/zones`, `GET/POST /shipping/methods` |
| POS / sync | `POST /devices/register`, `POST /devices/{id}/revoke`, `GET /sync/pull`, `POST /sync/push`, `POST /pos/cashier-login` (PIN) |
| Imports | `POST /imports` (upload), `PUT /imports/{id}/mapping`, `POST /imports/{id}/validate`, `POST /imports/{id}/run`, `GET /imports/{id}`, `GET /imports/{id}/errors.csv` |
| Notifications | `GET/PUT /message-templates`, `GET /message-log`, `POST /messages/test` |
| Alerts | `GET /alerts`, `POST /alerts/{id}/resolve` |
| Reports | `GET /reports/sales`, `/reports/products`, `/reports/customers`, `/reports/stock`, `/reports/cash-up`, `/reports/messages`, each with `?format=csv` |
| Settings | `GET/PATCH /settings` (capability `wsp_manage_settings`), `GET/PUT /settings/gateways/{id}` |
| Webhooks | `POST /webhooks/paystack`, `POST /webhooks/flutterwave`, `POST /webhooks/gateway3`, `POST /webhooks/whatsapp`, `POST /webhooks/sms` |
| Staff | `GET/POST /staff`, `PATCH /staff/{id}` (role, PIN, discount limit), `DELETE /staff/{id}` |
| Platform (main site only) | `POST /platform/applications`, Owner-only: `/platform/tenants`, `/platform/plans`, `/platform/invoices`, `/platform/impersonate`, `/platform/webhooks/paystack` |

Maintain `docs/API.md` with request/response examples for every endpoint.

---

## 19. Security and data protection

### 19.1 Application security

- Every REST route has a `permission_callback` checking a specific `wsp_` capability (or device/customer/webhook auth). No route uses `__return_true` except the public tracking page (token-checked) and webhooks (signature-checked).
- All SQL through `$wpdb->prepare()` or a query builder that binds parameters. No string concatenation of input into SQL.
- All output escaped (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` for descriptions). React screens never use `dangerouslySetInnerHTML` with user content.
- Nonces for all admin-ajax/forms; REST uses the WordPress REST nonce for cookie auth.
- File uploads (proof of payment, product photos, imports): allow-list MIME types (jpg, png, webp, pdf, csv, xlsx), size limits, stored through the media library; proof-of-payment files are private (not publicly listable; served via signed URL).
- Rate limits (per IP and per phone/user, using the object cache): OTP requests, OTP verify, login, checkout, proof upload, public tracking.
- Device tokens and session tokens are stored only as SHA-256 hashes.
- Staff PINs for POS are hashed with `wp_hash_password`; 5 wrong attempts lock that cashier on that device for 10 minutes.
- Admin and Owner accounts SHOULD use two-factor sign-in (OTP to phone).

### 19.2 Money and stock safety

- All money arithmetic in integers via the `Money` value object; rounding rules documented (round half up to the kobo for percentage fees/discounts).
- Totals are always recomputed server-side; client totals are only displayed, never trusted.
- Payment confirmation by staff requires `wsp_confirm_payments` and records who confirmed; amount and order are shown with the uploaded proof side by side.
- Suggested anti-fraud default: transfers above a threshold (setting) need a manager confirmation.

### 19.3 Tenant isolation

See 6.4. Isolation tests are mandatory (21.3).

### 19.4 Secrets

- Gateway secret keys, WhatsApp tokens and SMS API keys are encrypted at rest with libsodium (`sodium_crypto_secretbox`) using a key from `wp-config.php` constant `WSP_ENCRYPTION_KEY` (never stored in the database).
- Secrets are write-only in the UI (show last 4 characters only).
- Never log secrets, OTP codes, full tokens or full card data. WSP never touches card numbers (gateways handle them).

### 19.5 Personal data

- Nigeria's data protection law (Nigeria Data Protection Act 2023) applies to customer phone numbers, names and addresses. Each store has a privacy notice page (template provided); merchants are data controllers for their customers, the Owner is a processor. Have the Owner confirm legal wording (`TBD-OWNER`).
- Support customer data export and deletion requests per store (deletion anonymises customers but keeps order and ledger records for accounting).
- Backups encrypted; retention policy documented in `docs/HOSTING.md`.

---

## 20. Performance and reliability targets

| Metric | Target |
|---|---|
| Catalog size per store | 50,000 variants, 200,000 stock movements/month without degradation |
| Back office API (list/search) | p95 < 300 ms at 20,000 variants |
| Reservation (checkout / quick order save) | p95 < 400 ms; correct under 50 concurrent buyers on the same variant |
| Storefront product page | Server response < 600 ms uncached; full page cached for guests; group-priced fragments rendered per session without caching the wholesale price into the public cache |
| POS sync push | 100 queued sales applied in < 10 s |
| Import | 10,000 rows in < 5 minutes in the background |
| Message dispatch | Order message queued within 5 s of the event |
| Uptime | 99.5% monthly (hosting + monitoring) |
| Backups | Daily full + binary logs/point-in-time if the host supports it; restore tested monthly |

Caching rules:
- Guests (retail prices) may use full-page cache. Signed-in customers bypass page cache, or prices are filled by a small uncached request. Wholesale prices MUST never be stored in a shared cache.
- Stock numbers on product pages are fetched fresh (short TTL or uncached fragment) because they change often.

Monitoring: error tracking (e.g. Sentry) for PHP and JS, uptime checks per tenant domain, job queue backlog alerts, failed webhook alerts, nightly ledger verification report to the Owner.

---

## 21. Testing requirements

### 21.1 Unit tests (engine, no browser)

- Price resolution: every branch of 10.1, including wholesaler buying below the tier minimum (R1), customer-specific price expiry, pack-unit prices, derived pack prices.
- Ledger: every movement type's effect; `stock_levels` always equals ledger sums after random sequences of operations (property-based test).
- Order state machines: every valid and invalid transition.
- Money: arithmetic, rounding, gateway fee formulas.
- Phone normalisation.

### 21.2 Concurrency tests

- 50 parallel reservations for a variant with 10 available → exactly 10 succeed, 40 fail with `insufficient_stock`, `reserved = 10`, ledger matches.
- Same idempotency key sent 5 times in parallel → one order.
- Webhook delivered twice → one payment.

### 21.3 Multisite isolation tests

- Two tenants; staff of A cannot read or write B through any endpoint; device token of A rejected on B; customer session of A rejected on B; network jobs switch and restore blog context correctly.

### 21.4 Integration tests

- Paystack and Flutterwave adapters against their test modes (test keys from the project's example config, never live keys): initialize, verify, webhook signature valid/invalid, amount mismatch rejected, refund.
- WhatsApp/SMS adapters with provider sandboxes or mocked HTTP.
- Import: valid file, file with errors, re-import updating by SKU.

### 21.5 End-to-end (Playwright)

1. Wholesaler registers (auto-approved), sees wholesale price, buys 3 pieces below tier at wholesale price, pays by transfer with proof, staff confirm, pack, dispatch, customer opens tracking page and sees timeline. (R1, R5, R6)
2. Staff record a WhatsApp order in quick order, stock drops on storefront immediately, tap-to-send link contains correct summary. (R2)
3. Uploader bulk-uploads photos, fills the grid, publishes; product live with variants. (R3)
4. Customer selects a colour; gallery switches to that colour's photos; URL updates; reload keeps the colour. (R4)
5. POS: go offline, sell the last 2 units, sell same variant online meanwhile, reconnect → both sales recorded, oversell alert raised, nothing lost. (R2)
6. Owner: application → plan → invoice paid (test mode) → store provisioned → merchant logs in.

### 21.6 Definition of done for any feature

- Engine logic covered by unit tests; money/stock paths also by concurrency tests where relevant.
- REST endpoint documented in `docs/API.md`.
- Capability checks in place and tested.
- Works on a 360 px-wide phone screen.
- All strings translatable; no hard-coded currency symbols outside the `Money` formatter.
- No PHP warnings/notices; PHPCS (WordPress coding standards adapted for PSR-4 classes) and ESLint pass.
- Audit log entries for sensitive actions.
- `DECISIONS.md` updated for any deviation.

---

## 22. Build plan

Estimates assume one experienced full-stack developer full time; with an AI coding agent paired with a reviewer, phases may run faster but the order MUST stay the same. The engine is built **multisite-aware from day 1** (develop on a local multisite with at least two subsites), even though the billing layer comes last.

### 22.1 Phase 0 — Discovery (week 0, 3–5 days, in parallel with setup)

- Owner (or developer) spends a day with 2 wholesalers in different categories watching how orders, stock and dispatch actually work. Collect: a Bumpa export file, how they write prices (piece/dozen/carton), how many staff and phones, how they confirm transfers, how parcels leave (rider, park, courier).
- Record findings in `docs/DISCOVERY.md` and any changes to this brief.
- Confirm with the Owner: third gateway provider, plan prices, product name if ready.
- **Exit:** discovery notes written; pilot merchant identified.

### 22.2 Phase 1 — Foundation (weeks 1–2)

- Repository, Docker multisite environment, CI (lint + tests), Composer/npm workspaces.
- Core: constants/brand, container, migrations runner, `Money`, phone normaliser, idempotency middleware, audit logger, Action Scheduler setup.
- All store tables (section 8.1–8.5) and network tables (8.6) with migrations.
- Engine: Catalog service, Pricing service (10.1), Inventory/ledger service (section 9 complete incl. verify command), Order service core (create/reserve/cancel/commit), Customer + group services.
- REST skeleton with auth modes and error format.
- **Acceptance:** all unit + concurrency + isolation tests in 21.1–21.3 pass for the engine; `wp wsp stock verify` reports zero mismatches after a scripted random workload of 10,000 operations.

### 22.3 Phase 2 — Back office (weeks 3–5)

- React back office shell (phone-first navigation, dashboard placeholder).
- Products: quick add, options, variant grid, option photos, pack units, categories, archive.
- Bulk photo upload, spreadsheet import with validation preview and error report, bulk edit, restock screen, stock counts, transfers, locations.
- Price lists and groups UI, customer list/profile, special prices.
- Staff management with roles and capabilities; Uploader role.
- Audit log viewer, alerts list.
- **Gate:** a pilot merchant can load their real catalog (thousands of variants) and run stock in WSP alongside Bumpa for a week. Stock counts reconcile.

### 22.4 Phase 3 — Orders, payments, notifications (weeks 6–8)

- Quick order screen (11.2) with tap-to-send.
- Orders list, order detail, editing, cancel, refunds, printing (A4 + thermal).
- Payments: manual transfer + proof + confirmation, cash/terminal, pay on delivery, Paystack checkout/links, Paystack virtual accounts, Flutterwave; webhooks + reconciliation job; fee pass-through.
- Hold expiry job.
- Shipping zones/methods with Nigerian states/LGAs seed.
- Shipments, statuses, bulk status, public tracking page.
- Notifications: template system, WhatsApp Cloud API adapter, SMS adapter, message log and costs, merchant alerts, daily summary.
- **Gate:** the pilot shop takes real WhatsApp orders through quick order for at least one week; every order's stock, payment and dispatch is correct; customers receive tracking links.

### 22.5 Phase 4 — Storefront (weeks 9–11)

- Lean block theme + blocks: product grid, product page with swatches and photo switching, category pages, search, cart, checkout (shipping options, payment choice), account (OTP sign-in, orders, addresses), wholesale registration, tracking page styling.
- Interactivity API stores for swatch selection, cart, checkout.
- Group-aware pricing and caching rules (20).
- SEO basics: clean URLs, Open Graph per selected option, sitemap for products.
- **Gate:** pilot's website live on the same stock; E2E tests 1, 3, 4 pass.

### 22.6 Phase 5 — Point of sale and offline sync (weeks 12–14)

- POS PWA: device registration, cashier PIN, sell screen, payments incl. split, receipts, park sale, counter returns, cash-up.
- Offline storage, pull/push sync (15.3), oversell alerts, safety buffer, staleness indicators.
- Load test: 3 devices offline for 2 hours with 300 sales, reconnect simultaneously.
- **Gate (pilot-ready release):** E2E test 5 passes; two weeks in the pilot shop with deliberate network cuts; zero lost sales; ledger verify clean daily.

### 22.7 Phase 6 — Platform layer (weeks 15–17)

- Applications form and inbox, plans CRUD, PlanGate enforcement, usage counters.
- Paystack platform billing: setup invoices, subscriptions, webhooks, dunning states.
- Provisioning job (17.2), custom domains, first-run onboarding checklist.
- Owner console (17.5) incl. audited impersonation.
- Service requests.
- **Gate:** E2E test 6 passes; a second merchant is onboarded end to end without developer involvement.

### 22.8 After launch (backlog, in rough priority)

1. Courier API adapters (quotes, booking, tracking sync).
2. Customer credit / pay-later for trusted wholesalers (uses `credit_limit_kobo`).
3. Back-in-stock notifications.
4. Coupons and promotions.
5. Marketing broadcasts to opted-in customers (plan feature, marketing message pricing).
6. Supplier purchase orders.
7. Accounting exports.
8. Elementor widgets for merchants who insist on Elementor.
9. AI helpers: product descriptions from photos, demand-based reorder suggestions.

---

## 23. Risks

| Risk | Impact | Mitigation |
|---|---|---|
| Problem misread (requirements from one public post) | Building the wrong thing | Phase 0 discovery with 2 wholesalers; pilot from phase 2 |
| Offline oversells | Merchant expects "perfect sync" | Location pools, safety buffer, alerts, honest wording (15.5) |
| Hosting weakness (slow DB, no real cron) | Ledger and jobs fail | Managed VPS spec, Redis, system cron, monitoring; never shared hosting |
| Scope creep from merchants | Delays, unstable core | Fixed v1 scope; extras become paid add-ons or service requests |
| WhatsApp messaging costs (Meta charges every business message since 1 Oct 2026) | Margin erosion | Per-message cost log, plan message allowances, overage billing, SMS and tap-to-send fallbacks |
| Staff don't adopt quick order/POS | Ledger wrong | Speed targets (order in < 30 s), training videos, onboarding checklist |
| Fake transfer receipts | Merchant loses goods | Virtual accounts (auto-confirm), manager confirmation above threshold, side-by-side proof view |
| Bumpa migration format unknown | Slow onboarding | Get sample export in phase 0; generic importer as fallback |
| Single multisite = single point of failure | All merchants down together | Backups, staging site, migrations tested on staging, rollback plan, monitoring |
| Gateway/API changes (Paystack, Flutterwave, Meta) | Payments or messages break | Adapters isolated, integration tests in CI against test modes, reconciliation job |
| Third gateway undefined | Delay | Adapter interface ready; build when Owner confirms |

---

## 24. Open questions for the Owner (keep updated in `docs/OPEN_QUESTIONS.md`)

1. **"GoPay":** which provider exactly (OPay, another)? Until answered, implement Paystack and Flutterwave only.
2. **Product name** and platform domain.
3. **Plan prices, setup fees and limits** (17.3).
4. **Pilot merchant(s)** and what they receive for piloting (discount, free months).
5. **WhatsApp API route:** direct Meta Cloud API with each merchant's own number, or a Business Solution Provider? Who pays Meta (merchant's own Meta billing, or the Owner bills usage)?
6. **SMS provider** choice.
7. **Hosting provider** and region.
8. **Legal:** terms of service for merchants, privacy notice template, data processing terms.
9. **Trial period** length, if any.
10. Is **self-serve signup** wanted at launch or only Owner-reviewed applications? (Default: reviewed only.)

---

## 25. Decisions already made (do not reopen without the Owner)

| # | Decision | Reason |
|---|---|---|
| D1 | Build on WordPress multisite, own commerce engine, no WooCommerce/Shopify/Bumpa | Owner's direction; full control of stock and pricing model |
| D2 | Sold as a hosted retainer service with monthly plans, never as a downloadable plugin | Owner's business model |
| D3 | Proprietary, closed source | Owner's decision |
| D4 | Any product category; options are merchant-defined | Owner's decision; widens the market |
| D5 | Stock as an append-only ledger in custom tables with transactional writes | Root cause of R2 |
| D6 | Price resolved by customer → group → tiers → base | Root cause of R1/R5 |
| D7 | Wholesale registration auto-approved by default, switchable to manual per store | Owner's decision |
| D8 | Gateways: Paystack + Flutterwave + third provider (TBD) behind one adapter interface | Owner's decision |
| D9 | WhatsApp: both official API and tap-to-send | Owner's decision; cost control |
| D10 | Customers are not WordPress users; phone + OTP sign-in | Multisite user table is shared across tenants |
| D11 | Counter sales offline are always accepted; mismatches become alerts | Goods already left the shop; no lost sales |
| D12 | Stock commits at packed (default) or dispatched, not at payment | Paid goods still on the shelf are still reserved |
| D13 | Money in kobo integers | Correct totals |

---

## 26. Instructions for AI coding agents

1. Start with Phase 0/1 tasks. Before coding, create `docs/DECISIONS.md` and `docs/OPEN_QUESTIONS.md` if missing, and copy this brief to `docs/BRIEF.md`.
2. Work in small, reviewable commits, one concern each. Each commit message references the section of this brief it implements (e.g. "Inventory: reservation (§9.4)").
3. Write tests for engine code **before or with** the code. Do not merge engine code without tests.
4. Never bypass the engine from a controller, screen, job or WP-CLI command.
5. Never add a dependency on WooCommerce or any commerce plugin. Small, well-maintained libraries (Action Scheduler, PhpSpreadsheet for XLSX, Dexie, Workbox) are allowed; record each new dependency in `DECISIONS.md` with its licence.
6. Use test/sandbox keys only. Never commit secrets; use `.env`/`wp-config` constants and provide `.env.example`.
7. When a value is `TBD-OWNER`, use a clearly marked placeholder and keep it configurable.
8. When requirements conflict, the priority order is: correctness of stock and money → tenant isolation and security → the six requirements R1–R6 → speed for staff on phones → everything else.
9. At the end of each phase, produce a short `docs/PHASE_n_REPORT.md`: what was built, what was tested, deviations, open questions, and the gate result.
10. If you are unsure, ask the Owner in `OPEN_QUESTIONS.md` and continue with the safest reversible choice.

---

## 27. Sources

- Paystack, "Transactions pricing" (Nigeria fees, dedicated virtual accounts, fee pass-through formula), support article edited 15 September 2026: https://support.paystack.com/en/articles/2130306
- Yournotify, "WhatsApp Business API Pricing in Nigeria: What Changed in October 2026?" (Meta per-message pricing changes and Nigeria rates): https://yournotify.com/blog/whatsapp-pricing-update-2026/
- Flutterwave local fee (approx. 1.4%) from public 2026 Nigerian gateway comparisons; verify against Flutterwave's own pricing page during implementation.
- Requirements source: Instagram post by `didi_jewerly`, 13 October 2026, shared by the Owner as screenshots (paraphrased in section 1.3).
- Design patterns referenced (not code): WooCommerce custom order tables and Action Scheduler; Shopify inventory-per-location, draft orders, fulfilments, B2B price lists, idempotency keys.

*End of brief.*
