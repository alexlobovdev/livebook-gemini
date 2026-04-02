FROM php:8.3-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
      git \
      unzip \
      libpq-dev \
      libzip-dev \
      libicu-dev \
      libonig-dev \
      curl \
    && docker-php-ext-install pdo_pgsql intl bcmath opcache zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/entrypoint.sh /entrypoint.sh

RUN mkdir -p /var/www/app/storage/app/temp \
    && chown -R www-data:www-data /var/www/app/storage \
    && chmod +x /entrypoint.sh \
    && echo 'env[TMPDIR] = /var/www/app/storage/app/temp' >> /usr/local/etc/php-fpm.d/www.conf

ENTRYPOINT ["/entrypoint.sh"]
WORKDIR /var/www/app
