# Frontend build: Vite output (public/build) only. node_modules never reaches the runtime image.
# Node 22.23.4, pinned by digest (the tag is informational). Bump the tag and digest together; the
# `vite` service in docker-compose.dev.yml uses the same image.
FROM node:22.23.4-alpine@sha256:fe4f6d74e20370acb04ad889077d14d0f64a9c8ea6a42bc60cddf1e844699914 AS assets

WORKDIR /app

# Dependencies first so the layer is reused until package.json or the lockfile changes. Optional
# dependencies stay: Vite (rolldown) and Tailwind ship their native binaries as optional packages.
COPY package.json package-lock.json .npmrc ./
RUN npm ci --ignore-scripts --no-audit --no-fund

# Only what the build reads: app.css lists exactly resources/js and resources/views as Tailwind sources.
COPY vite.config.js ./
COPY resources/css ./resources/css
COPY resources/js ./resources/js
COPY resources/views ./resources/views
RUN npm run build


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
COPY --from=assets /app/public/build ./public/build
RUN composer dump-autoload --optimize \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R a+rwX storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000"]
