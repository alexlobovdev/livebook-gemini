#!/usr/bin/env sh
set -eu

mkdir -p storage/framework/{cache,sessions,views} storage/logs storage/app/temp bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

exec php-fpm
