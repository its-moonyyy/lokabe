# ---- Stage 1: build the React frontend -------------------------------
FROM node:20-slim AS frontend
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

# ---- Stage 2: PHP + Apache + MariaDB runtime --------------------------
FROM php:8.2-apache

ENV PORT=10000 \
    API_BASE=/api \
    DB_HOST=127.0.0.1 \
    DB_PORT=3306 \
    DB_NAME=poker \
    DB_USER=root \
    DB_PASS=

# Built frontend
COPY --from=frontend /app/dist /var/www/html

# PHP bindings + MariaDB server/client
RUN docker-php-ext-install pdo_mysql \
    && apt-get update \
    && apt-get install -y --no-install-recommends mariadb-server mariadb-client \
    && rm -rf /var/lib/apt/lists/*

# Application + deployment files
COPY api/ /var/www/api/
COPY docker/apache.conf.template /etc/apache2/poker-apache.conf.template
COPY docker/mariadb.cnf /etc/mysql/mariadb.conf.d/90-poker.cnf
COPY docker/mpm_prefork.conf /etc/apache2/mods-available/mpm_prefork.conf
COPY docker/start.sh /start.sh

RUN a2enmod rewrite && chmod +x /start.sh

EXPOSE 10000
CMD ["/start.sh"]