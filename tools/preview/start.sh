#!/usr/bin/env bash
#
# Restart the local Store Keeper preview (MariaDB + PHP built-in server).
#
# The toolchain lives in /tmp, which this workspace does not keep across
# restores. If the binaries are missing, follow docs/PREVIEW.md to rebuild
# them, then run this script again.
set -euo pipefail

TOOLS=/tmp/wsp-tools
PREVIEW=/tmp/wsp-preview
PORT=3000
MYSQL=(/tmp/wsp-tools/mariadb-11.4.4-linux-systemd-x86_64/bin/mariadb
  --no-defaults --socket=/tmp/wsp-tools/mysql.sock -uroot)

if [[ ! -x "$TOOLS/php" ]]; then
  echo "PHP is missing at $TOOLS/php — rebuild the toolchain (docs/PREVIEW.md)." >&2
  exit 1
fi

if ! "$MYSQL" -e 'SELECT 1' >/dev/null 2>&1; then
  echo "Starting MariaDB..."
  cd "$TOOLS/mariadb-11.4.4-linux-systemd-x86_64"
  setsid nohup ./bin/mariadbd --no-defaults \
    --basedir="$PWD" \
    --datadir=/tmp/wsp-tools/mysql-data \
    --socket=/tmp/wsp-tools/mysql.sock \
    --port=3307 \
    --bind-address=127.0.0.1 \
    --pid-file=/tmp/wsp-tools/mariadb.pid \
    --skip-name-resolve > /tmp/wsp-tools/mariadb.log 2>&1 < /dev/null &

  for _ in $(seq 1 30); do
    sleep 1
    if "$MYSQL" -e 'SELECT 1' >/dev/null 2>&1; then break; fi
  done
fi

if ! ss -ltn 2>/dev/null | grep -q ":$PORT "; then
  echo "Starting the preview server on 0.0.0.0:$PORT..."
  cp "$(dirname "$0")/router.php" "$PREVIEW/router.php"
  cd "$PREVIEW"
  setsid nohup "$TOOLS/php" -S "0.0.0.0:$PORT" -t "$PREVIEW/wordpress" "$PREVIEW/router.php" \
    > "$PREVIEW/server.log" 2>&1 < /dev/null &
  sleep 3
fi

if ss -ltn 2>/dev/null | grep -q ":$PORT "; then
  echo "Preview is up on port $PORT. Show it with cloud_show_dev_server($PORT)."
else
  echo "Preview server did not start — see $PREVIEW/server.log" >&2
  exit 1
fi
