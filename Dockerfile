FROM dunglas/frankenphp:1-php8.4

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl unzip git \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions pdo_pgsql pcntl posix redis rdkafka intl zip opcache bcmath

COPY docker/zz-app.ini $PHP_INI_DIR/conf.d/zz-app.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R a+rwX storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000"]
