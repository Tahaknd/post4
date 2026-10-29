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


# Runtime image. BASE IMAGE PIN POLICY: the node stage above is pinned by digest (it only builds assets and
# never reaches the runtime). `dunglas/frankenphp:1-php8.4` and `composer:2` below are deliberately FLOATING
# tags (major-version tracks): they pick up security fixes of the base OS, PHP 8.4 and Composer 2 on every
# fresh build, at the price that two builds of the same commit can differ. Reproducibility comes from
# composer.lock and package-lock.json, not from these tags. Pinning them by digest, building a --no-dev
# runtime variant and running as a non-root user are Milestone 8 items (the dev stack needs the dev
# dependencies in this image: Pest and Pint live in require-dev and `docker compose exec app php artisan test`
# runs from the image's /app/vendor).
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

# Same probe as the app service of docker-compose.yml (which repeats it so that its timing can be tuned there);
# worker and scheduler share this image and switch the check off (`healthcheck: disable: true`).
HEALTHCHECK --interval=5s --timeout=3s --start-period=60s --retries=30 \
    CMD curl -fsS http://localhost:8000/up || exit 1

ENTRYPOINT ["entrypoint.sh"]
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000"]
