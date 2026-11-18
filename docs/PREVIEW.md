# Preview environment

A throwaway WordPress multisite with Store Keeper installed, used to look at the
product while Phase 2 screens are built. Nothing here is a deployment, and none
of it is production configuration.

## What is running

| Piece | Value |
| --- | --- |
| Preview host | `pv-bd9d595656cc-7eb4904d-5f7f-40a7-a319-04bff4ef9dcc.style.dev` (changes when the workspace is restored; update `SK_PREVIEW_HOST`, run `wp search-replace`, restart) |
| Store Keeper back office | `https://<preview-host>/ladies-corner/wp-admin/admin.php?page=storekeeper` |
| Network admin | `https://<preview-host>/wp-admin/` |
| Subsite | `/ladies-corner/` (`Ladies Corner`) |
| Admin login | `admin` / `StoreKeeper2026!` |
| PHP | 8.3.14 static build, `/tmp/wsp-tools/php` |
| MariaDB | 11.4.4, `127.0.0.1:3307`, socket `/tmp/wsp-tools/mysql.sock` |
| WordPress | `/tmp/wsp-preview/wordpress` |
| Server log | `/tmp/wsp-preview/server.log` |
| WordPress debug log | `/tmp/wsp-preview/debug.log` |

Both plugins are symlinked from this repository into `wp-content/plugins`, so
code edits are live without copying files.

Seeded demo data (`tools/preview/seed.php`): 3 products / 7 variants, 310 units
of opening stock at *Balogun shop*, one approved wholesale customer (Ada Obi).

The preview also sets two storefront options the cart/checkout tests rely on:
`storekeeper_flat_shipping_kobo = 150000` (a ₦1,500 delivery option appears beside
free pickup) and `storekeeper_bank_details` (shown on the order confirmation).
Clear the first option to see checkout with pickup only.

Storefront routes: `/ladies-corner/sign-in/`, `/ladies-corner/cart/`,
`/ladies-corner/checkout/` and `/ladies-corner/order-received/{number}/?token=…`.

### Trying sign-in

The code is emailed on a live store (D-036), but a preview host has no mail server,
so it runs the `log` transport: the code goes to the PHP error log and the page can
show it on screen, but only when **both** switches are on, so it cannot happen by
accident on a live site:

```bash
wp --url=https://<preview-host>/ladies-corner option update storekeeper_otp_show_code 1
# and wp-config.php must define WP_DEBUG as true
```

Sign in at `/ladies-corner/sign-in/` with the seeded customer's number,
`0803 123 4567` (whose email is `ada@example.test`); the code appears in a “Preview only” notice above the form, and
the pages then show wholesale prices (₦6,200 for the gold hoops instead of ₦8,500).
Turn the option off (`option delete storekeeper_otp_show_code`) to see the
production behaviour, where the code reaches only the PHP error log (or, on a live
store with the `email` transport, the customer's inbox).

### Trying sign-up

`/register/` works like sign-in: the code is written to the PHP error log and,
with the two switches above on, shown on the confirm screen. A new number is
approved immediately in an auto-approval group; switch a group to manual
approval in the back office to see the pending state and its notice on
`/account/`.

### Trying the notification outbox

A preview host has no mail server, so the preview uses the log transport:

```bash
wp --url=https://<preview-host>/ladies-corner option update storekeeper_notification_transport log
wp --url=https://<preview-host>/ladies-corner option update storekeeper_notification_email shop@example.test
wp --url=https://<preview-host>/ladies-corner storekeeper messages dispatch
```

A checkout with an email address queues two rows in `storekeeper_message_log`
(buyer confirmation and staff alert); `dispatch` sends them and the log line shows
the rendered message. Remove the option to go back to `wp_mail`.

## Opening the back office

The preview panel loads pages in an iframe, and WordPress sends
`X-Frame-Options: SAMEORIGIN` on `wp-admin` and `wp-login.php`, so the admin
screen cannot render inside that panel. Open it in a real tab instead:

1. Visit `https://<preview-host>/wp-login.php`.
2. Sign in with the credentials above.
3. WordPress lands on the network admin; pick **Ladies Corner → Store Keeper**,
   or go straight to
   `https://<preview-host>/ladies-corner/wp-admin/admin.php?page=storekeeper`.

Once signed in, the whole wp-admin area works in that tab. The public front
page still renders fine in the panel, because it does not send frame headers.

## Restarting

```bash
tools/preview/start.sh
```

It serves on port 3000, the port this chat's preview panel uses. Then show it
with `cloud_show_dev_server(3000)`.

`/tmp` is not part of the workspace checkpoint, so after an archive/restore the
toolchain, the database and the WordPress files can all be gone. `start.sh`
reports that plainly rather than half-starting.

## Rebuilding the toolchain from scratch

```bash
mkdir -p /tmp/wsp-tools /tmp/wsp-preview && cd /tmp/wsp-tools

# PHP (static build — the bulk build includes mysqli, gd, intl, redis)
curl -fL --retry 5 --retry-all-errors -o php.tar.gz https://dl.static-php.dev/static-php-cli/bulk/php-8.3.14-cli-linux-x86_64.tar.gz
tar xzf php.tar.gz   # extracts ./php directly

# MariaDB
curl -fsSL -o mariadb.tar.gz https://archive.mariadb.org/mariadb-11.4.4/bintar-linux-systemd-x86_64/mariadb-11.4.4-linux-systemd-x86_64.tar.gz
tar xzf mariadb.tar.gz
cd mariadb-11.4.4-linux-systemd-x86_64
./scripts/mariadb-install-db --no-defaults --basedir=$PWD \
  --datadir=/tmp/wsp-tools/mysql-data --auth-root-authentication-method=normal

# WP-CLI
curl -fsSL -o /tmp/wsp-tools/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
```

Run WP-CLI with `-d memory_limit=1G`: unpacking WordPress through the phar
exhausts the 128 MB default and fails half-way. The same applies to `core download`,
`eval-file` and seeding.

Database and WordPress:

```bash
cd /tmp/wsp-preview
/tmp/wsp-tools/php /tmp/wsp-tools/wp-cli.phar core download --path=/tmp/wsp-preview/wordpress
# then create wp-config.php (see "Preview wp-config notes" below)
DB=/tmp/wsp-tools/mariadb-11.4.4-linux-systemd-x86_64/bin/mariadb
$DB --no-defaults --socket=/tmp/wsp-tools/mysql.sock -uroot \
  -e 'CREATE DATABASE storekeeper; CREATE DATABASE storekeeper_test;'

WP="/tmp/wsp-tools/php -d memory_limit=1G /tmp/wsp-tools/wp-cli.phar --path=/tmp/wsp-preview/wordpress"
$WP core multisite-install --url="https://$PREVIEW_HOST" --subdomains=0 \
  --title="Store Keeper" --admin_user=admin --admin_password='StoreKeeper2026!' \
  --admin_email=admin@example.test --skip-email

ln -s "$PWD/plugins/storekeeper-commerce"   /tmp/wsp-preview/wordpress/wp-content/plugins/storekeeper-commerce
ln -s "$PWD/plugins/storekeeper-platform"   /tmp/wsp-preview/wordpress/wp-content/plugins/storekeeper-platform
ln -s "$PWD/plugins/storekeeper-mu-loader"  /tmp/wsp-preview/wordpress/wp-content/mu-plugins/storekeeper-mu-loader

$WP plugin activate --network storekeeper-commerce storekeeper-platform
$WP site create --slug=ladies-corner --title="Ladies Corner"
$WP storekeeper migrate --network
SK_SEED_BLOG=2 $WP --url="https://$PREVIEW_HOST/ladies-corner" eval-file tools/preview/seed.php

# Storefront theme, routes and demo product photos.
ln -sfn "$PWD/theme/storekeeper-storefront" /tmp/wsp-preview/wordpress/wp-content/themes/storekeeper-storefront
$WP --url="https://$PREVIEW_HOST/ladies-corner" theme activate storekeeper-storefront
$WP --url="https://$PREVIEW_HOST/ladies-corner" rewrite flush
$WP --url="https://$PREVIEW_HOST/ladies-corner" eval-file tools/preview/demo-photos.php
```

Storefront URLs: `/ladies-corner/`, `/ladies-corner/shop/`,
`/ladies-corner/product/{slug}/`, `/ladies-corner/product-category/{slug}/`.
The `wp-admin` link in the back-office rail still works; the shop hides nothing.

## Preview wp-config notes

The preview's `wp-config.php` differs from a normal install in three ways:

- `WP_HOME` / `WP_SITEURL` follow `HTTP_HOST`, so the preview answers on
  whatever hostname the tunnel gives it. Loopback requests (health checks) are
  mapped onto `SK_PREVIEW_HOST`, because multisite only knows the registered
  preview domain and would otherwise redirect them to port 80.
- The scheme is `https` and `$_SERVER['HTTPS'] = 'on'` for any host that is not
  loopback, because the cloud preview proxy terminates TLS. This is required for
  more than `force_ssl_admin()`: WordPress builds asset URLs with
  `set_url_scheme()`, which falls back to the *request* scheme, so without it the
  page is served with `http://` CSS/JS URLs and the browser blocks them as mixed
  content (the stylesheet silently does not load). The side effect is that auth
  cookies are marked `secure`, so a plain-HTTP `curl` client will not replay them;
  a browser over the preview URL is unaffected.
- `WP_DEBUG` is on and logs to `/tmp/wsp-preview/debug.log`, never to disk in
  the workspace.

`DISALLOW_FILE_EDIT` is set, and no production secret is used anywhere.

## Router

`tools/preview/router.php` serves real files and hands everything else to
WordPress. Multisite admin URLs (`/ladies-corner/wp-admin/...`) have no file
behind them, so the router strips the subsite segment, executes the real script
with corrected `SCRIPT_NAME`, and lets WordPress resolve the blog from the
request URI. Requests that are neither files nor scripts fall through to
`index.php`.

## Tests without the preview

`tools/preview/run-tests.sh` starts MariaDB if it is not running and then runs
PHPUnit against `storekeeper_test`. Each cloud command gets a fresh process tree,
so run it per command rather than relying on a server started earlier:

```bash
tools/preview/run-tests.sh
```

## Shipping and tracking in the preview

`ShippingService::seedDefaults()` runs on activation, but the existing preview store
was seeded on 2026-11-17 by hand through WP-CLI, so it carries demo data that is
**not** part of the code:

```bash
wp eval '$c=\StoreKeeper\Commerce\Core\Plugin::container();
  $s=$c->get(\StoreKeeper\Commerce\Shipping\ShippingService::class);
  $a=\StoreKeeper\Commerce\Core\Audit\Actor::system();
  $z=$s->createZone(["name"=>"Lagos","areas"=>[["state"=>"Lagos","lga"=>null]],"sort_order"=>10],$a);
  $s->createMethod(["zone_id"=>(int)$z["id"],"name"=>"Lagos same-day rider","type"=>"flat","config"=>["amount_kobo"=>250000]],$a);
  $s->createMethod(["zone_id"=>null,"name"=>"Pick up at the shop","type"=>"pickup","config"=>[]],$a);' \
  --url=https://<preview-host>/ladies-corner
```

With that data, `/checkout/` shows pickup always and the Lagos rider option once
the state/LGA fields say Lagos (`/checkout/?state=Lagos&lga=Ikeja`). A state with
no zone and no catch-all shows pickup only. `wp rewrite flush` is required once
after deploying the new `track/` and `pos/` rewrite rules.

### POS

`/ladies-corner/pos/` serves the standalone POS app (D-039). A manager signed into
wp-admin registers the device with a name and location; the returned `skd_…`
token is kept in the browser's `localStorage`. Set a cashier PIN as a manager
through the app's **Menu → Cashier PINs** (or `POST /pos/pins` with a cookie and
`manage_staff`) before signing in. `wp rewrite flush` is required after deploying.
The page is not covered by browser tests; open it once after a preview rebuild.

Order `SK-00002` in the preview has one parcel packed and dispatched (GIG Logistics,
waybill `GIG-7781`, rider Musa `08035550000`), so
`/track/SK-00002/?t={public_token}` renders a three-step timeline and
`/track/SK-00002/` without the token is a 404.

## Known gaps in this environment

- Only the `ladies-corner` subsite is seeded; the main site's store tables
  exist but are empty. That is a deliberate scope limit, not a bug.
- The seed script is not idempotent for locations: running it twice adds a
  second `Balogun shop`. Products are recreated per run.
- No WP test suite and no browser checks are wired up here; the engine's PHPUnit
  suite is the test gate (`vendor/bin/phpunit`).
