# Pocket: the web app (web/) and its API (server/) in one image, listening on
# port 80. nginx serves the built web app and sends /api to Laravel in php-fpm;
# a bundled redis holds the Horizon job queue and the cache of Firefly answers.
# Migrations run on start. Built and deployed by .github/workflows/deploy.yml
# (DEPLOYING.md).
#
#   docker build --build-arg APP_VERSION=dev -t firefly-pocket .

FROM composer:2.8 AS composer_base

#-----------------------------------------------------------------
# The web app: a static build, so node stays out of the final image. The
# files are the same for every platform, so it is built once, on the build
# machine's own (no emulation for the arm64 image).
FROM --platform=$BUILDPLATFORM node:22-alpine AS web

WORKDIR /web
COPY web/package.json web/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY web/ .
RUN npm run build

#-----------------------------------------------------------------
FROM alpine:3.24 AS base

RUN apk add --no-cache \
    nginx \
    redis \
    supervisor \
    php84 \
    php84-session \
    php84-ctype \
    php84-fpm \
    php84-pdo \
    php84-opcache \
    php84-zip \
    php84-phar \
    php84-iconv \
    php84-cli \
    php84-curl \
    php84-openssl \
    php84-mbstring \
    php84-tokenizer \
    php84-fileinfo \
    php84-json \
    php84-xml \
    php84-xmlwriter \
    php84-simplexml \
    php84-dom \
    php84-pdo_mysql \
    php84-pdo_pgsql \
    php84-pdo_sqlite \
    php84-pcntl \
    php84-posix \
    php84-gmp \
    php84-bcmath
RUN ln -s /usr/bin/php84 /usr/bin/php

#-----------------------------------------------------------------
# The API
FROM base AS server

COPY --from=composer_base /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_PROCESS_TIMEOUT=600

WORKDIR /var/www/html
COPY server/composer.json server/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction

COPY server/ .
# Defaults only: the container's environment overrides every value in it
RUN cp .env.example .env
RUN composer dump-autoload --no-dev --optimize --no-interaction
RUN php artisan key:generate
ARG APP_VERSION
RUN echo "$APP_VERSION" > VERSION
# Git does not keep these empty directories
RUN mkdir -p storage/app/custom-icons storage/logs \
        storage/framework/cache/data storage/framework/sessions storage/framework/views \
        bootstrap/cache database/data

#-----------------------------------------------------------------
FROM base

RUN adduser \
    --disabled-password \
    --gecos "" \
    --home /var/www/html \
    --ingroup www-data \
    --no-create-home \
    www-data

COPY --from=server --chown=www-data:www-data /var/www/html /var/www/html
COPY --from=web --chown=www-data:www-data /web/dist /var/www/web
RUN chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database/data

# Configure supervisor
COPY docker/conf/supervisor/supervisord.conf /etc/supervisord.conf
COPY docker/conf/supervisor/ /etc/supervisor.d/
RUN rm /etc/supervisor.d/supervisord.conf \
    && mkdir -p /tmp/supervisor && chown -R www-data:www-data /tmp/supervisor

# Configure PHP
RUN mkdir -p /run/php/ && touch /run/php/php8.4-fpm.pid && chown -R www-data:www-data /run/php
RUN mkdir -p /var/log/php84 && chown -R www-data:www-data /var/log/php84
COPY docker/conf/php-fpm/ /etc/php84/

# Configure redis (the Horizon job queue and the response cache)
COPY docker/conf/redis/redis.conf /etc/redis-pocket.conf
RUN mkdir -p /tmp/redis && chown -R www-data:www-data /tmp/redis

# Configure nginx
COPY docker/conf/nginx/ /etc/nginx/
RUN mkdir -p -m 770 /tmp/nginx /run/nginx \
    && chown -R www-data:www-data /tmp/nginx /run/nginx /var/log/nginx /var/lib/nginx
# The entrypoint renders http.d/default.conf from the template, so www-data must be able to write there
RUN chown -R www-data:www-data /etc/nginx/http.d

COPY --chmod=755 docker/docker-entrypoint.d/ /docker-entrypoint.d/

# Production defaults; FireflyX's compose file sets the rest
ENV APP_ENV=production
ENV APP_DEBUG=false
ENV DB_CONNECTION=sqlite

# Port nginx listens on inside the container. Set it to an unprivileged port (e.g. 8080)
# on hosts that don't allow non-root processes to bind port 80
ENV NGINX_PORT=80

# Queued jobs (voice transcription, the cache warm-up) run on the bundled redis
# through Horizon, and the cache of Firefly answers lives there too (README "Caching")
ENV QUEUE_CONNECTION=redis
ENV CACHE_DRIVER=redis
ENV REDIS_CLIENT=predis

EXPOSE 80
USER www-data

ENTRYPOINT ["/docker-entrypoint.d/start.sh"]
