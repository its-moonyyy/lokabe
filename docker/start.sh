#!/usr/bin/env bash
set -euo pipefail

# ---- Configure Apache on Render's injected PORT ----------------------
PORT="${PORT:-10000}"
sed "s/__PORT__/${PORT}/g" /etc/apache2/poker-apache.conf.template \
    > /etc/apache2/sites-enabled/000-poker.conf

# ---- MariaDB ----------------------------------------------------------
if [ ! -d "/var/lib/mysql/mysql" ]; then
    echo "Initializing MariaDB data directory..."
    mariadb-install-db --user=mysql --datadir=/var/lib/mysql \
        --auth-root-authentication-method=normal >/dev/null 2>&1
fi

mkdir -p /run/mysqld
chown -R mysql:mysql /var/lib/mysql /run/mysqld 2>/dev/null || true

echo "Starting MariaDB..."
mariadbd --user=mysql --datadir=/var/lib/mysql \
    --socket=/run/mysqld/mysqld.sock \
    --pid-file=/run/mysqld/mysqld.pid \
    --log-error=/var/lib/mysql/mariadb.err &
MARIADB_PID=$!

for i in $(seq 1 60); do
    if mariadb-admin --socket=/run/mysqld/mysqld.sock -uroot ping >/dev/null 2>&1; then
        break
    fi
    if ! kill -0 "$MARIADB_PID" 2>/dev/null; then
        wait "$MARIADB_PID"
        echo "MariaDB died with exit code / signal: $?" >&2
        echo "--- /var/lib/mysql/mariadb.err ---" >&2
        tail -60 /var/lib/mysql/mariadb.err >&2 || true
        echo "--- ulimit -a ---" >&2
        ulimit -a >&2 || true
        echo "--- df -h /var/lib/mysql ---" >&2
        df -h /var/lib/mysql >&2 || true
        cat /proc/meminfo | grep -E "MemTotal|MemFree|MemAvailable" >&2 || true
        exit 1
    fi
    sleep 1
done

# Allow the PHP app to connect over TCP as root (default is unix_socket auth)
mariadb --socket=/run/mysqld/mysqld.sock -uroot <<'SQL'
ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('');
CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED VIA mysql_native_password USING PASSWORD('');
GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION;
CREATE USER IF NOT EXISTS 'root'@'%' IDENTIFIED VIA mysql_native_password USING PASSWORD('');
GRANT ALL PRIVILEGES ON *.* TO 'root'@'%' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL

if ! mariadb --socket=/run/mysqld/mysqld.sock -uroot \
    -e "USE \`${DB_NAME:-poker}\`" 2>/dev/null; then
    echo "Seeding database..."
    mariadb --socket=/run/mysqld/mysqld.sock -uroot < /var/www/api/schema.sql
fi

# ---- Apache (foreground = PID 1) --------------------------------------
echo "Starting Apache on port ${PORT}..."
exec apache2-foreground