# Phase 1 report — Foundation

Date: 15 November 2026 · Brief version 1.0 · Working name: **Store Keeper**

## What was built

### Repository and tooling (§7.1, §22.2)
- Monorepo layout: `plugins/storekeeper-commerce` (engine + REST + CLI), `plugins/storekeeper-platform` (network layer, Phase 6), `plugins/storekeeper-mu-loader` (environment guards), `apps/admin`, `apps/pos`, `apps/storefront` (workspaces reserved for Phases 2, 5, 4), `tools/`, `docs/`.
- Composer (PSR-4, PHPUnit 11), npm workspaces, `phpunit.xml.dist` with separate `unit` and `integration` suites.
- `docker-compose.yml`: WordPress 6.7 / PHP 8.3 multisite, MariaDB 11.4, Redis 7, Mailpit, WP-CLI, plus a separate `storekeeper_test` database for PHPUnit.
- CI (`​.github/workflows/ci.yml`): PHP 8.3 + MariaDB 11.4 service, `composer lint`, full test suite.

### Core (§7.3)
`Brand` (single source of every name/prefix), `Money` (integer minor units, documented half-up rounding), `Phone` (E.164, Nigerian input forms), `Uuid`, `Clock`/`SystemClock`/`FrozenClock`, `Strings`, `Container`, `Capabilities` (the §5.2 matrix), `Installer` (migrations + role registration), `SchemaMigrator`/`Migrator`, `IdempotencyService`, `AuditLogger`, `AlertService`.

Database access goes through `Core\Database\Connection` with two drivers: `WpdbConnection` (production, every statement through `$wpdb->prepare()`) and `PdoConnection` (tests, CLI). See D-002.

### Schema (§8.1–8.6)
All 44 store tables and 8 network tables are created by versioned migrations, plus `{p}schema_migrations` bookkeeping (D-003). Verified against MariaDB 11.4: 53 tables + 2 bookkeeping tables.

### Engine
- **Pricing (§10.1)**: full resolution order — customer price → group list best tier (with the `ignore_item_minimums` fallback that solves R1) → base tier → derived pack price → `PriceNotFound`. Quote API and audit-logged price writes.
- **Inventory (§9)**: append-only ledger with `opening`, `receive`, `reserve`, `release`, `commit`, `counter_sale`, `return`, `transfer_out/in`, `adjust`; transactional writes with the level row locked first; conditional reservation; oversell accepted only for counter sales and always alerted; low-stock alerts; transfers; stock counts; ledger verification (`verify(--fix)`).
- **Catalog (R3, R4)**: products with merchant-defined options, cartesian variant grid, option-value photos, rebuild that archives instead of deleting, quick add, categories, pack units, search.
- **Customers (R1, R5)**: phone-normalised customers (never WordPress users), groups with auto/manual approval, special prices, addresses, default group seeding.
- **Orders (§11.2, §12)**: one order object for every channel; reservation inside the creation transaction; idempotency key per order; hold expiry by payment method; draft orders that hold no stock; cancel with reservation release; handover that commits reservations; derived payment/fulfilment status; gap-tolerant order numbers.
- **REST skeleton (§18)**: namespace-wide no-store caching, capability-based `permission_callback` helper, idempotent write helper, and the stable error mapper. Endpoint routes land with their screens in Phases 2–4 (recorded in `docs/API.md`).
- **WP-CLI**: `wp storekeeper migrate [--network]` (switch/restore blog per site in `try/finally`) and `wp storekeeper stock verify [--fix]` / `stock movements`.

### Security and correctness guards
Capability prefixing and role matrix, audit entries for price/stock/order/customer changes, typed domain errors mapped to HTTP codes, no float money arithmetic, append-only movements, hooks fired after commit only, and fixed lock ordering to remove the deadlock risk found by the concurrency test.

## What was tested

Command: `vendor/bin/phpunit` (requires `SK_TEST_DSN`, see `.env.example`).

```
OK (96 tests, 1188 assertions)
```

- 30 unit tests: money parsing/formatting/rounding, phone normalisation, order state machine, payment/fulfilment derivation, capabilities and role matrix.
- 66 integration tests against real MariaDB, covering:
  - every table from §8.1–8.6 and migration idempotency;
  - all price-resolution branches, including R1 ("wholesaler buys 3 below the 12-piece tier and still pays wholesale");
  - every ledger movement type, oversell alerting and deduplication, transfers, counts, tamper detection and `--fix`;
  - a 250-operation randomized workload asserting `stock_levels` always equals the ledger and `reserved` never goes negative;
  - **50 concurrent reservations for 10 available units → exactly 10 succeed, 40 fail with `insufficient_stock`, 10 movements, clean ledger** (§21.2);
  - order create/reserve/idempotent replay/cancel/handover, insufficient-stock rollback, drafts, manual-price permission, totals;
  - idempotency replay and `409` conflict semantics;
  - customer auto/manual approval, duplicate phone rejection, special prices, addresses;
  - catalog grid, option photos, rebuild, archive, quick add;
  - two-tenant isolation over two table prefixes (§21.3 at engine level).

## What was not run (honest limits)

- **No WordPress instance was available** in this environment, so the WordPress integration test suite, activation hooks, real `$wpdb` driver and admin notices were linted but not executed. They are exercised in the pilot environment.
- **PHPCS/WordPress coding standards** are not yet installed or configured; only `php -l` and PHPUnit ran.
- **No browser/E2E tests** — Playwright arrives with the storefront in Phase 4.
- The static PHP used for verification was 8.3.14; the brief's floor is 8.2 and `composer.json` pins it.

## Deviations and decisions

All recorded in `docs/DECISIONS.md` (D-001 … D-011). The material ones:

- Product is called **Store Keeper**; names come from `Core/Brand.php` (D-001).
- The engine depends on a narrow `Connection` interface rather than `$wpdb` directly, so money/stock logic can be tested without WordPress (D-002).
- v1 reserves each order line from a single location (the first sellable one with stock), as §9.4 permits (D-005).

## Open questions carried forward

`docs/OPEN_QUESTIONS.md`: GoPay provider, final product name/domain, plan pricing, pilot merchant, WhatsApp route and payer, SMS provider, hosting, legal wording, trial length, self-serve signup, Bumpa export sample.

## Gate result

§22.2 acceptance is met at engine level: all unit, property, concurrency and isolation tests pass, and `verify` reports zero mismatches after the randomized workload. The remaining piece before a pilot is running the same suite inside a real multisite install with two subsites, which is the first task of Phase 2 setup.
