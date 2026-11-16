# Phase 4 report — customers, prices, locations and staff

Scope note: the brief's §22.5 "Phase 4" is the storefront. The back-office
items §22.3 lists — price lists and groups, customer list and profile, special
prices, locations, staff — were still outstanding, so this phase finished those
screens before the storefront (D-020). The storefront is next.

## What shipped

### Customers and wholesale approval

- `GET /customers` lists customers with the group that prices them, spend and
  approval state, filtered by search (name, business, phone), group or approval.
- `POST /customers` creates one; `POST /customers/{uuid}/approve|reject` decides a
  wholesale application; `POST /customers/{uuid}/group` moves a customer between
  groups; `PUT /customers/{uuid}/prices` sets a special price agreed with one
  customer.
- `GET /customers/{uuid}` returns the customer with their recent orders and
  special prices.
- The screen creates customers, searches the list, changes a group inline and
  approves or rejects pending applicants.

### Prices

- `GET /price-lists`, `GET /price-lists/{id}/prices` (product, variant, pack unit,
  quantity tier, amount), `PUT /price-lists/{id}/prices` to set a tier, and
  `POST /pricing/quote` to ask what a given customer would pay right now — the
  staff's check that a wholesaler really sees wholesale.
- The screen picks a list, shows its tiers and saves a new quantity tier. The
  preview store now shows the retail tier at ₦12,000 and a 12-piece tier at
  ₦10,000 on the same variant.

### Locations

- `POST /locations` (name, type, address, online-sellable, pickup flag) joins the
  existing `GET /locations`. The screen lists and creates them; the preview has a
  warehouse added next to the shop.

### Staff and alerts

- `GET /staff` lists the people who can work in the store, their role and whether
  they may confirm payments, plus the six roles. A WordPress administrator counts
  as the owner, so the list is never empty on a fresh store.
- `GET /alerts` and `POST /alerts/{id}/resolve`, with the open alerts shown on the
  dashboard and resolvable there.

### A correctness fix found while building the screens

Customer spend was always ₦0: `customers.total_spent_kobo`, `order_count` and
`last_order_at` were never maintained. `CustomerRepository::refreshTotals()`
recomputes them from the customer's orders (paid amount, ignoring cancelled
orders) and `OrderService` calls it on create and on every status change. Tested.

## Verification

- `vendor/bin/phpunit`: 113 tests, 1277 assertions, all passing.
  `tests/Integration/BackOfficeQueriesTest.php` covers the customer list and its
  filters, approval moving a pending applicant into the group they asked for,
  price tiers with product names, location creation and its validation, the
  per-customer order filter, and customer spend following a paid order.
- Against the preview store: every new endpoint answered with real data —
  4 customers, 2 groups, 2 price lists, staff list with the owner, 1 alert;
  creating a location returned `201`; setting a price returned `200` and the tier
  appeared in the list. In the browser: Customers, Prices, Locations and Staff
  tabs all render live data (screenshots in `artifacts/`).

## Back-office design upgrade

The screens were rendered inside a stock WordPress page: WP tabs, WP buttons, WP
notice boxes. They are now their own app shell — a dark section rail with the
store name, a light workspace with a page header and live chips, one type scale,
indigo primary actions, soft status pills and borderless tables. The WordPress
admin bar, menu and footer are hidden on this screen only, and the rail carries
`View store`, `WordPress admin`, the signed-in user and sign-out. Below 900 px
the rail becomes a scrollable strip, so the phone layout stays one-handed.
See `docs/DESIGN.md` for the tokens and rules.

## Storefront

The shop is live on the same stock and prices: `/shop/`, category pages, search,
product pages with option swatches that switch photos, and Open Graph/canonical
metadata. Prices resolve through the browsing customer's group. Routes, caching,
the theme and what remains (cart, checkout, OTP sign-in) are in
[docs/STOREFRONT.md](STOREFRONT.md).

## Not done yet

- **Special-price and customer-detail screens.** The endpoints exist; there is no
  detail view yet, so a per-customer price is set through the API.
- **Staff management is read-only.** Inviting a colleague still happens on the
  WordPress Users screen with a store role; adding users from here (and the
  capability editor §5.2 describes) is not built.
- **Storefront** — the brief's Phase 4 proper — is the next phase, along with the
  group-aware pricing it depends on.
