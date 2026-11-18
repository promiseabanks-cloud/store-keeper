# Back-office screens

The merchant's whole workspace is one wp-admin page: **Store Keeper**
(`/wp-admin/admin.php?page=storekeeper`). The shell and its design rules are in
`docs/DESIGN.md`; this file is the screen reference — what each section does, who
may do it, and what it deliberately does not do.

`assets/admin/admin.js` renders the shell and the catalogue/order/customer
screens. `assets/admin/screens.js` renders Shipping, Parcels and Messages and is
exposed as `window.storekeeperScreens`.

## Sections

| Section | What it is for | Writes | Capability |
|---|---|---|---|
| Overview | Products, variants, stock, orders today, awaiting payment, open alerts, low-stock table | Alert resolve | reads `view_revenue`, resolve `resolve_alerts` |
| Products | Add a product with its first variant, price and opening stock; catalogue table | `POST /products` | `edit_products` |
| Orders | Counter order form, order table, mark paid, payment link, cancel | `POST /orders`, `/payments`, `/payment-link`, `/cancel` | `create_orders`, `confirm_payments`, `cancel_orders` |
| Parcels | To-pack list, parcel detail, status moves, bulk pack, packing slip and dispatch sheet | `POST /shipments/bulk-status`, `PATCH /shipments/{uuid}` | reads `create_orders`, writes `update_shipments` |
| Shipping | Zones and areas, methods, fee config, on/off | `POST /shipping/zones`, `/shipping/methods`, `PATCH /shipping/methods/{id}` | reads `create_orders`, writes `manage_settings` |
| Customers | New customer, search, approve/reject, group change | `POST /customers`, `/approve`, `/reject`, `/group` | `manage_customers`, `approve_wholesale` |
| Prices | Price list and quantity-tier editor | `PUT /price-lists/{id}/prices` | `edit_prices` |
| Locations | Add a shop/warehouse and mark it sellable online | `POST /locations` | `manage_settings` |
| Staff | Who works in the store and who may confirm payments | read-only | `manage_staff` |
| Messages | Outbox (queued/sent/failed), send now, template editor | `POST /messages/dispatch`, `PUT /messages/templates/{event}` | reads `create_orders`, writes `manage_settings` |
| Gateways | Merchant gateway keys, test mode, enable | `PUT /settings/payments` | `manage_settings` |

Staff users themselves are still created on the WordPress Users screen and given
one of the six store roles (D-021); the Staff section only reports them.

## Parcels

The list defaults to **To pack** — parcels that exist but whose stock has not
been committed. Each row carries the order number, recipient, phone, delivery
address and piece count from the order, so a parcel can be picked and checked
without opening the order.

- **Open** loads `GET /shipments/{uuid}`: the parcel's lines (with how many of
  the order line they carry), the order's payment status, a status form and the
  timeline.
- **Status form** offers only the transitions `ShipmentStatus` allows. Dispatch
  asks for a carrier (or the method already on the parcel), plus optional
  waybill, rider and phone. Delivering a parcel on an unpaid order offers the
  cash collected, which becomes a real payment. A failed delivery requires a
  reason. A return can put the goods back on the shelf (`restock`).
- **Bulk pack** sends `POST /shipments/bulk-status` with `packed`. Bulk dispatch
  is deliberately absent: every parcel needs its own carrier and waybill, and a
  bulk button that guessed one would put wrong data on the timeline.
- **Printing.** "Packing slip" renders the order, recipient, address and parcel
  lines; "Print dispatch sheet" renders every selected parcel with a blank
  waybill column and a rider signature line. Printing uses the browser's print
  dialog (`window.print()`), not a PDF service, printer driver or courier API.

Packing is the moment stock leaves `reserved` and becomes `on_hand`-minus, and
it is what moves the order to `partially_fulfilled`/`fulfilled` (D-038). A parcel
that is only `pending` still holds its pieces out of the next parcel's default
lines.

## Shipping

Zones are an ordered list. The **first** zone whose areas match the address wins;
an area is a whole state (`Lagos`) or one LGA (`Lagos / Eket`). A zone with no
areas is the catch-all and should sort last. The screen accepts areas one per
line as `State` or `State / LGA` — there is no LGA picker yet (Q-018).

Methods quote one of five types (D-037):

| Type | Config | Meaning |
|---|---|---|
| `flat` | `amount_kobo` | One fee everywhere in the zone |
| `free_over` | `threshold_kobo`, `else_amount_kobo` | Free above the cart subtotal, otherwise the else fee |
| `weight_band` | `bands[{max_grams, amount_kobo}]`, optional `over_per_kg_kobo` | First band the cart weight fits; extra per kg above the last |
| `pickup` | `instructions` | No fee, always offered, no zone |
| `quote_later` | `message` | Zero on the order, flagged `quoted_later` |

The screen edits bands as lines of `grams : naira`. Fees are the merchant's
numbers; no price is invented. Turning a method off stops checkout offering it
without deleting its history.

## Messages

`message_log` is both the audit trail and the outbox (D-032). The list shows the
newest 100 messages with status, attempts and error, plus counts for
queued/sent/failed.

- **Send queued now** runs the same dispatch as the hourly job and the WP-CLI
  command. It only takes rows still `queued`; a failure stays queued and becomes
  `failed` after five attempts.
- **Templates** start from the code defaults in `Notifications\Templates` for six
  events: `order_created_buyer`, `order_created_staff`, `shipment_dispatched`,
  `shipment_ready_for_pickup`, `shipment_delivered`, `otp_login`. Saving writes
  one `message_templates` row for that event/channel; **Use the built-in copy**
  deletes the row again. Placeholders are `{{name}}` and the event's available
  names are listed above the editor.
- A message already queued keeps the rendered subject and body it was created
  with; editing a template never rewrites history.

Only email is deliverable today. With `storekeeper_notification_transport =
log` (the preview) messages are written to the PHP error log; a live store needs
working `wp_mail` and a From address (Q-017). WhatsApp and SMS adapters are not
built (Q-005, Q-006).

## What is intentionally absent

- No refunds screen, no virtual-account setup, no imports/exports.
- No staff invitation flow (WordPress Users owns accounts).
- No courier API, printer integration or PDF storage.
- No customer-facing editing of message templates; overrides are staff-only.
- No rich-text editor for templates: a subject and a plain-text body, because
  the only channel that can send them is email/log.

## Verifying by hand

1. Rebuild the preview stack (`docs/PREVIEW.md`), sign in as an administrator and
   open the Store Keeper screen.
2. Shipping: add a catch-all zone, add a flat method, place a storefront order
   with an address in that zone and confirm the fee on the order.
3. Parcels: open an order, create a parcel (`POST /orders/{uuid}/shipments`),
   refresh Parcels, open it, pack it, dispatch it with a carrier, print the slip.
4. Messages: place an order, open Messages, confirm the buyer/staff rows are
   `queued`, press **Send queued now** and confirm `sent` under the `log`
   transport. Edit one template and place another order to see the new copy.

None of steps 2–4 has been exercised in a browser in this workspace yet; the
services behind them are covered by `ShipmentTest`, `NotificationsTest`,
`ShippingTest` and the full suite.
