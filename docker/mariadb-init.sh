#!/usr/bin/env bash
set -euo pipefail

# Initializes a complete MariaDB datadir at IMAGE BUILD time so the runtime
# container never has to provision it (avoids startup races on slow instances).

if [ ! -d "/var/lib/mysql/mysql" ]; then
    mariadb-install-db --user=mysql --datadir=/var/lib/mysql \
        --auth-root-authentication-method=normal >/dev/null 2>&1
fi

mkdir -p /run/mysqld
chown -R mysql:mysql /var/lib/mysql /run/mysqld

echo "Booting MariaDB to seed..."
mariadbd --user=mysql --datadir=/var/lib/mysql \
    --socket=/run/mysqld/mysqld.sock \
    --pid-file=/run/mysqld/mysqld.pid \
    --skip-networking \
    --skip-log-bin &
PID=$!

for i in $(seq 1 60); do
    if mariadb-admin --socket=/run/mysqld/mysqld.sock -uroot ping >/dev/null 2>&1; then
        break
    fi
    if ! kill -0 "$PID" 2>/dev/null; then
        echo "MariaDB failed during build seeding" >&2
        exit 1
    fi
    sleep 1
done

# Root over TCP + seed data (rooms, bots, schema)
mariadb --socket=/run/mysqld/mysqld.sock -uroot <<'SQL'
ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('');
CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED VIA mysql_native_password USING PASSWORD('');
GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION;
CREATE USER IF NOT EXISTS 'root'@'%' IDENTIFIED VIA mysql_native_password USING PASSWORD('');
GRANT ALL PRIVILEGES ON *.* TO 'root'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL

mariadb --socket=/run/mysqld/mysqld.sock -uroot < /var/www/api/schema.sql

mariadb --socket=/run/mysqld/mysqld.sock -uroot -e "SHUTDOWN" || true
wait "$PID"
echo "MariaDB datadir initialized and seeded."