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
    --innodb-use-native-aio=0 \
    --innodb-buffer-pool-size=64M \
    --max-connections=25 \
    --log-error=/var/log/mariadb.err &
MARIADB_PID=$!

for i in $(seq 1 60); do
    if mariadb-admin --socket=/run/mysqld/mysqld.sock -uroot ping >/dev/null 2>&1; then
        break
    fi
    if ! kill -0 "$MARIADB_PID" 2>/dev/null; then
        echo "MariaDB failed to start" >&2
        echo "--- /var/log/mariadb.err ---" >&2
        tail -60 /var/log/mariadb.err >&2 || true
        exit 1
    fi
    sleep 1
done

# Allow the PHP app to connect over TCP as root (default is unix_socket auth)
mariadb --socket=/run/mysqld/mysqld.sock -uroot <<'SQL'
ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('');
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