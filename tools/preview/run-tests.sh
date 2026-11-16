#!/bin/bash
# Start the throwaway MariaDB for this command, run the engine suite, exit with
# PHPUnit's status. Each cloud command gets a fresh process tree, so the server is
# started here rather than relying on one started earlier.
set -u
BASE=/tmp/wsp-tools
MARIADB="$BASE/mariadb-11.4.4-linux-systemd-x86_64"

if ! "$MARIADB/bin/mariadb" --no-defaults --socket="$BASE/mysql.sock" -uroot -e "SELECT 1" >/dev/null 2>&1; then
    rm -f "$BASE/mysql.sock" "$BASE/mysqld.pid"
    nohup "$MARIADB/bin/mariadbd" --no-defaults \
        --basedir="$MARIADB" \
        --datadir="$BASE/mysql-data" \
        --port=3307 --bind-address=127.0.0.1 \
        --socket="$BASE/mysql.sock" \
        --pid-file="$BASE/mysqld.pid" \
        > "$BASE/mysqld.log" 2>&1 &

    for _ in $(seq 1 30); do
        if "$MARIADB/bin/mariadb" --no-defaults --socket="$BASE/mysql.sock" -uroot -e "SELECT 1" >/dev/null 2>&1; then
            break
        fi
        sleep 1
    done
fi

"$MARIADB/bin/mariadb" --no-defaults --socket="$BASE/mysql.sock" -uroot \
    -e "CREATE DATABASE IF NOT EXISTS storekeeper_test;" >/dev/null 2>&1

cd "$(dirname "$0")/../.." || exit 1
exec "$BASE/php" vendor/bin/phpunit "$@"
