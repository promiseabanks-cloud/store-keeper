# Storefront

The shop reads the same tables the back office writes. It never edits stock or
prices: every price on the page came from `PricingService::resolve()`, every
availability number from the stock ledger, so the website cannot drift from the
shop floor.

## What ships in this phase

| Piece | Where |
|---|---|
| Routes `/shop/`, `/shop/page/2/`, `/product/{slug}/`, `/product-category/{slug}/` | `src/Storefront/Storefront.php` |
| Read model for grids and product pages | `src/Storefront/CatalogView.php` |
| Product grid block + `[storekeeper_products]` shortcode | `src/Storefront/Blocks.php`, `blocks/product-grid/` |
| Cart, checkout and order pages `/cart/`, `/checkout/`, `/order-received/{number}/` | `Storefront.php`, `CartSession`, `CartActions`, `Cart\CartService`, `Checkout\CheckoutService` |
| Templates (shop, product, cart, checkout, order, not found) | `templates/storefront/` |
| Theme | `theme/storekeeper-storefront/` |
| Swatch interaction | `assets/storefront/product.js` |
| Styles | `assets/storefront/storefront.css` |

A product is a row in the engine's own tables, not a WordPress post, so the
product page is a route rather than a template hierarchy match. `template_include`
returns the plugin template, which calls the theme's `get_header()` and
`get_footer()`, so the shop keeps the merchant's header and footer and the products
stay in one place.

## Group-aware pricing

`CustomerContext` answers "who is browsing" and is the seam for phone + OTP
sign-in (§11.5). Today it returns the row supplied through the
`storekeeper/storefront/customer` filter and nothing else, which means:

- a guest is priced from the default (retail) group;
- an approved customer is priced from their own group, including tiers and any
  price agreed with them (`customer_prices`);
- a wholesale-only product is hidden from guests and shown to wholesale groups.

The filter is one line to wire when sign-in lands; no template changes.

## Sign-in

Customers sign in at `/sign-in/` with the phone number the shop has on file and a
6-digit code. Two server-rendered steps, no JavaScript:

1. `storekeeper_otp_request` checks the number and the rate limits, stores a
   bcrypt hash of the code and calls `OtpSender::send()`.
2. `storekeeper_otp_verify` reads the pending number from the `skf_otp` cookie
   (10 minutes, HTTP-only) and opens a session: `skf_session` holds 32 random bytes,
   the database only their SHA-256. 90 days, sliding forward while in use.

`CustomerContext` resolves the cookie, so every price on the shop, in the cart and
at checkout resolves through `PricingService` with that customer — an approved
wholesale customer sees wholesale prices immediately. Checkout attaches
`customer_id` to the order, so the group used is recorded with the order.

A cart picked up while signed out is attached to the customer at sign-in; an active
cart from another device is folded in, capped at available stock (D-031).

> **Delivery is not wired yet.** Q-005 (WhatsApp route) and Q-006 (SMS provider)
> are unanswered, so `LogOtpSender` writes the code to the PHP error log. The
> preview shows it on screen when `WP_DEBUG` and `storekeeper_otp_show_code` are
> both on; never enable that option in production (D-030).

Not built in this increment: wholesale registration from the storefront (staff
create customers, and the approval queue already exists), account order history,
and the address book (the services exist; there is no screen).

## Cart and checkout

A visitor's cart is identified by a 32-byte token in the `skf_cart` cookie (only
its SHA-256 is stored, D-026). The cart stores variant ids and quantities; the
price, product name and availability shown on the cart page were resolved on that
request. Adding more than the ledger holds is refused with "Only N left"; a price
change, a tier change or a sold-out variant is visible immediately.

The cart and checkout pages are ordinary forms (`admin-post.php`, nonce-checked)
so they work without JavaScript (D-028). WordPress nonces expire after 24 hours
(12 with the session grace period), so a page left open overnight posts a stale
nonce. That is handled as ordinary shopper behaviour, not as an attack: the post
changes nothing, flashes "This page had expired, so the cart was not changed" (or
"…so the order was not placed" on checkout) and redirects back to a freshly
rendered form with a new nonce. It never reaches `wp_nonce_ays()`, whose bare
"link you followed has expired" screen loses the cart's context. Checkout validates the shopper, picks the
shipping fee (pickup is free; delivery appears only when
`storekeeper_flat_shipping_kobo` is set), then calls `OrderService::create()` with
`channel=web`. OrderService re-prices and reserves in one transaction, so a
browser cannot influence the price and two shoppers cannot oversell the last
piece.

## Creating an account

`/register/` asks for a name, a phone number, and "How are you buying?" — the
choices are the shop's self-registerable customer groups (§11.1). Choosing a
wholesale group asks for a business name. The phone is confirmed with a 6-digit
code before anything is saved, so a mistyped number never becomes a customer.

Approval is the group's own rule. An auto group approves at once and the shopper
sees its prices immediately. A manual group leaves the account `pending` in the
retail group, and the account page says so plainly; staff approval moves them to
the requested group. Codes come from the same sender as sign-in, so the preview
code and its two switches are the same (see `PREVIEW.md`).

## The account page

`/account/` is for signed-in customers only; a guest is redirected to sign-in. It
lists the customer's orders (newest first) with the current status, a link to the
token-protected order page, and "Order again", which refills the cart from that
order at today's prices and names anything that is no longer available. It also
shows saved addresses and the sign-out form. Orders belonging to another customer
cannot be repeated even if the number is guessed.

The checkout form takes an optional email address. It is stored on the order's
contact details and used for the confirmation message; a signed-in customer's
stored email is used when the field is left blank. Nothing is sent during the
checkout request — the message is queued and dispatched later (D-032).

Guest checkout (D-027) does **not** attach an existing customer record: typing
someone else's phone number cannot place an order on their account. The phone is
kept on the order's shipping/contact JSON. Signed-in customers are attached and
priced from their group; wholesale checkout therefore needs OTP sign-in, which is
not built yet.

A converted cart remembers its order, so a repeated submit returns the same order
instead of charging twice. A converted or 30-day-expired cart is reused as a fresh
one on the next add, with a new uuid, so the visitor's existing cookie keeps
working instead of colliding with the token's unique row. The confirmation page requires
`?token={public_token}` and states the payment status honestly: bank transfer is
unpaid until staff confirm it, and the bank details come from the
`storekeeper_bank_details` option (plain text, line breaks preserved).

## Caching

Guest grids are cached for 5 minutes in a transient keyed by blog, price list,
category and limit. Signed-in customers never read or write that cache, so a
wholesale price cannot be served to a guest. Any of these events bumps a version
number that invalidates every fragment at once:

`catalog/product_created`, `stock/received`, `stock/moved`,
`stock/transfer_received`, `order/created`, `order/cancelled`, `order/completed`.

Product pages resolve prices per request and are not cached yet; they are cheap
(one product, a handful of variants) and correct beats fast while the catalog is
small.

## Option swatches and photos

- Every swatch is a real link: `?colour=rose-gold`. Without JavaScript the page
  reloads on the chosen value and the server renders that value's photos, price and
  stock, so a shared link opens correctly and link previews use the right image.
- With JavaScript the photos of every value are already in the page payload, so a
  swatch switch is instant and the URL is updated with `history.replaceState`.
- Photos scoped to a value come first, then the product's general photos.
- A value whose only variant has no stock still renders; choosing it shows
  "Out of stock" instead of hiding the option (§11.4).

## SEO

Clean URLs, `document_title_parts` per page, canonical link, Open Graph title,
description, URL and image, Twitter card. A product XML sitemap is **not** done.

## Installing the theme

The theme lives in `theme/storekeeper-storefront/` in this repository and calls the
plugin's `StoreKeeper\Commerce\Storefront\Storefront` class, so the plugin must be
active. For the preview site:

```bash
ln -sfn "$PWD/theme/storekeeper-storefront" /tmp/wsp-preview/wordpress/wp-content/themes/storekeeper-storefront
wp theme activate storekeeper-storefront --url=https://<preview-host>/ladies-corner
wp rewrite flush --url=https://<preview-host>/ladies-corner
wp eval-file tools/preview/demo-photos.php --url=https://<preview-host>/ladies-corner
```

`demo-photos.php` draws placeholder product photos with GD and attaches them to
each colour value. It is preview tooling, not part of the plugin.

## Not done yet

- Wholesale registration form, account order history, address book screen,
  order tracking page. (Sign-in itself is shipped; see above.)
- Shipping zones and calculated delivery (checkout offers pickup + one flat fee).
- Public REST endpoints for cart/checkout (D-028); the forms are the supported path.
- Wholesale registration form.
- Product XML sitemap and structured data.
- Any block theme: the grid block works, but the product page route still needs a
  theme with `header.php`/`footer.php` (see D-024).
