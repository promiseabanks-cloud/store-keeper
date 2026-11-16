# Preview environment

A throwaway WordPress multisite with Store Keeper installed, used to look at the
product while Phase 2 screens are built. Nothing here is a deployment, and none
of it is production configuration.

## What is running

| Piece | Value |
| --- | --- |
| Preview host | `pv-bd9d595656cc-1a9b80e5-e62e-4423-adb3-9697a1f81e03.style.dev` (changes when the workspace is restored; update `SK_PREVIEW_HOST`, run `wp search-replace`, restart) |
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
curl -fsSL -o php.tar.gz https://dl.static-php.dev/static-php-cli/bulk/php-8.3.14-cli-linux-x86_64.tar.gz
tar xzf php.tar.gz && mv php-8.3.14-cli-linux-x86_64/php ./php

# MariaDB
curl -fsSL -o mariadb.tar.gz https://archive.mariadb.org/mariadb-11.4.4/bintar-linux-systemd-x86_64/mariadb-11.4.4-linux-systemd-x86_64.tar.gz
tar xzf mariadb.tar.gz
cd mariadb-11.4.4-linux-systemd-x86_64
./scripts/mariadb-install-db --no-defaults --basedir=$PWD \
  --datadir=/tmp/wsp-tools/mysql-data --auth-root-authentication-method=normal

# WP-CLI
curl -fsSL -o /tmp/wsp-tools/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
```

Database and WordPress:

```bash
cd /tmp/wsp-preview
/tmp/wsp-tools/php /tmp/wsp-tools/wp-cli.phar core download --path=/tmp/wsp-preview/wordpress
# then create wp-config.php (see "Preview wp-config notes" below)
DB=/tmp/wsp-tools/mariadb-11.4.4-linux-systemd-x86_64/bin/mariadb
$DB --no-defaults --socket=/tmp/wsp-tools/mysql.sock -uroot \
  -e 'CREATE DATABASE storekeeper; CREATE DATABASE storekeeper_test;'

WP="/tmp/wsp-tools/php /tmp/wsp-tools/wp-cli.phar --path=/tmp/wsp-preview/wordpress"
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
```

## Preview wp-config notes

The preview's `wp-config.php` differs from a normal install in three ways:

- `WP_HOME` / `WP_SITEURL` follow `HTTP_HOST`, so the preview answers on
  whatever hostname the tunnel gives it. Loopback requests (health checks) are
  mapped onto `SK_PREVIEW_HOST`, because multisite only knows the registered
  preview domain and would otherwise redirect them to port 80.
- The scheme is `https` and `$_SERVER['HTTPS'] = 'on'`, because the cloud
  preview proxy terminates TLS. Without that, `force_ssl_admin()`
  bounces every `wp-admin` request in a loop. The side effect is that auth
  cookies are marked `secure`, so a plain-HTTP `curl` client will not replay
  them; a browser over the preview URL is unaffected.
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

## Known gaps in this environment

- Only the `ladies-corner` subsite is seeded; the main site's store tables
  exist but are empty. That is a deliberate scope limit, not a bug.
- The seed script is not idempotent for locations: running it twice adds a
  second `Balogun shop`. Products are recreated per run.
- No WP test suite and no browser checks are wired up here; the engine's PHPUnit
  suite is the test gate (`vendor/bin/phpunit`).
