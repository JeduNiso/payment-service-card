#!/bin/bash -xe
set -e

# Ajusta estos valores según el servidor real
APP_DIR="/var/www/html/payment_card_service"
PHP_VERSION="8.2"
DOMAIN="payment-card-service.transoft.bo"

sudo apt-get update
sudo apt-get install -y software-properties-common ca-certificates curl unzip git gnupg lsb-release

# PHP 8.2
sudo add-apt-repository -y ppa:ondrej/php
sudo apt-get update
sudo apt-get install -y \
    php${PHP_VERSION} \
    php${PHP_VERSION}-cli \
    php${PHP_VERSION}-common \
    php${PHP_VERSION}-mysql \
    php${PHP_VERSION}-curl \
    php${PHP_VERSION}-soap \
    php${PHP_VERSION}-xml \
    php${PHP_VERSION}-gmp \
    php${PHP_VERSION}-mbstring \
    php${PHP_VERSION}-zip \
    php${PHP_VERSION}-intl \
    libapache2-mod-php${PHP_VERSION} \
    composer \
    mc \
    rsync

sudo a2enmod rewrite php${PHP_VERSION}
sudo a2dismod php7.4 2>/dev/null || true

if [ -d "$APP_DIR" ]; then
    cd "$APP_DIR"
    composer install --no-interaction --prefer-dist --no-progress
    cp -n .env.example .env || true
    php artisan key:generate --force || true
    php artisan storage:link || true
    php artisan config:clear || true
    php artisan cache:clear || true
    php artisan migrate --force || true
    npm install --no-fund --no-audit
    npm run build
fi

sudo systemctl restart apache2

echo "Instalación completa. Ajusta el VirtualHost y el dominio: ${DOMAIN}"
