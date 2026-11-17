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

Guest checkout (D-027) does **not** attach an existing customer record: typing
someone else's phone number cannot place an order on their account. The phone is
kept on the order's shipping/contact JSON. Signed-in customers are attached and
priced from their group; wholesale checkout therefore needs OTP sign-in, which is
not built yet.

A converted cart remembers its order, so a repeated submit returns the same order
instead of charging twice. The confirmation page requires
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

- Storefront sign-in (OTP), account, addresses, order tracking page.
- Shipping zones and calculated delivery (checkout offers pickup + one flat fee).
- Public REST endpoints for cart/checkout (D-028); the forms are the supported path.
- Wholesale registration form.
- Product XML sitemap and structured data.
- Any block theme: the grid block works, but the product page route still needs a
  theme with `header.php`/`footer.php` (see D-024).
