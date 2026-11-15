# Phase 2 — progress report (first slice)

Phase 2 is the back office: the screens a merchant actually works in. This is
the first slice only — the shell, the read paths it needs and the shape the rest
will follow. It is not the whole phase.

## What is in place

### REST (read + two writes)

Registered under `/storekeeper/v1` on each site:

| Route | Capability | Notes |
| --- | --- | --- |
| `GET /overview` | `storekeeper_view_revenue` | dashboard counters + low-stock rows |
| `GET /products` | `edit_products` | products with variant count and available units; `search`, `limit` |
| `POST /products` | `edit_products` | delegates to `CatalogService::createProduct`, honours `Idempotency-Key` |
| `GET /products/{uuid}` | `edit_products` | product, variants, media placeholder |
| `GET /locations` | `edit_products` | active locations |
| `GET /stock?variant_id=` | `edit_products` | online availability + recent movements |
| `POST /stock/receipts` | `receive_stock` | delegates to `InventoryService::receive`, honours `Idempotency-Key` |

Controllers contain no SQL and no business rules: they validate the request,
check a capability, call the engine and map `StoreKeeperException` to a
`WP_Error`. Unauthenticated requests return 401, not an empty list.

### Back office shell

- Top-level **Store Keeper** menu plus a **Products** submenu, both capability
  gated (`Admin\Menu`).
- A React screen (dashboard + products) using `@wordpress/element` and
  `@wordpress/api-fetch`, loaded with a REST nonce. It reads only from the REST
  API.
- **No build step yet.** `assets/admin/admin.js` is hand-written React using
  `createElement`, not JSX. The `@wordpress/scripts` pipeline (`apps/admin`)
  and its babel build are the next slice; when that lands this file is replaced
  by compiled output and the menu enqueue points at the build directory instead.

### Engine additions

- `CatalogRepository::listWithStock()` — product list with variant count and
  available units in one query.
- `Reports\OverviewService` — dashboard aggregates. Reports are the only place
  that queries aggregates directly; there are no writes there.

## Verification

- `vendor/bin/phpunit` — `OK (96 tests, 1188 assertions)` on MariaDB 11.4.
- Filtered `php -l` over every plugin PHP file — clean.
- Live WordPress (multisite, subsite `ladies-corner`):
  - `GET /overview` with a session + REST nonce returned the seeded numbers
    (3 products, 7 variants, 310 units); without credentials it returned 401.
  - `GET /products` returned the three seeded products with variant counts and
    available units.
  - `GET /stock?variant_id=1` returned 200 with an empty movement list (that id
    is above the seeded variants).
  - `wp-admin/admin.php?page=storekeeper` returned 200 with the app root, the
    localised nonce and both assets (`admin.js`, `admin.css`) served 200.

## Not done / not verified

- Browser check: the screen was opened in the cloud browser after signing in,
  and both tabs render live data (dashboard counters 3 / 7 / 310 / 0 / 0 / 0;
  product table with the three seeded products, variant counts and units). See
  `artifacts/backend-dashboard.png` and `artifacts/backend-products.png`.
  This also exposed a preview-only router bug where subsite asset URLs returned
  404, which is fixed.
- Write paths (`POST /products`, `POST /stock/receipts`) are wired but not
  exercised over HTTP; their services are covered by the engine suite.
- No admin-side PHPUnit or REST integration tests yet.
- Screens not started: product editor, variant grid UI, price lists, locations
  management, orders, payments, customers, reports, settings, POS, storefront.

## Follow-on order

1. `@wordpress/scripts` build for `apps/admin` + product editor screen.
2. Orders inbox and order detail (read first, then status transitions).
3. Payments: OPay init/verify against the gateway contract, then the receipts
   queue.
4. Customers/approvals, locations, price lists.
5. Reports and settings.
6. POS and storefront.
