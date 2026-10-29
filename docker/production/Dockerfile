# syntax=docker/dockerfile:1
#
# Production image for KumuliCP: PHP + Apache, running as www-data on port 8080.
#
# The app is fetched from GitHub during the build, so the build context is only this directory
# (entrypoint.sh, supervisord.conf, env.defaults) and a dirty local checkout never ends up in the image.
#
#   docker build -t kumulicp:main docker/production
#   docker build -t kumulicp:test --build-arg APP_REF=some-branch-or-tag docker/production
#
# While the app is under heavy development the image tracks the `main` branch. The commented-out
# `release` stage below is the replacement for when releases are cut.

# Exact PHP version, not the moving 8.4 tag: rebuilds are reproducible and the update is a reviewed change.
ARG PHP_VERSION=8.4.27

# --- base: PHP, Apache, extensions, supervisor -------------------------------------------------
FROM php:${PHP_VERSION}-apache-bookworm AS base
LABEL maintainer="Curtis Werstuik <cwerstuik@kumuli.ca>"

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# install php modules (dev headers are only needed to build the extensions below,
# so they're purged again in this same layer to keep them out of the final image)
RUN apt-get update && apt-get install -y --no-install-recommends \
    libcurl4-openssl-dev ldap-utils libldap-2.5-0 libldap-common libldap2-dev \
    libzip-dev libzip4 libgmp-dev libicu-dev libpng-dev \
 && docker-php-ext-install ldap pdo_mysql mysqli zip intl gmp bcmath gd exif sockets \
 && apt-get purge -y libcurl4-openssl-dev libldap2-dev libzip-dev libgmp-dev libicu-dev libpng-dev \
 && rm -rf /var/lib/apt/lists/*

# --- source: the app, from the repository's main branch ----------------------------------------
FROM base AS source
ARG APP_REPO_URL=https://github.com/kumulicp/kumulicp.git
# A branch or tag name (not a commit SHA). `main` while the app has no release cadence.
ARG APP_REF=main
RUN apt-get update && apt-get install -y --no-install-recommends git \
 && rm -rf /var/lib/apt/lists/*
RUN git clone --quiet --depth 1 --branch "${APP_REF}" "${APP_REPO_URL}" /app \
 && git -C /app rev-parse HEAD > /revision \
 && rm -rf /app/.git

# --- release (not used yet): the app, from a GitHub release --------------------------------------
# .github/workflows/release.yml publishes kumulicp-<tag>.zip with composer dependencies and compiled
# assets already inside, so a release build needs neither the `vendor` nor the `assets` stage.
# To switch: delete the `source`, `vendor` and `assets` stages, uncomment this one, and change the
# `COPY --from=assets ... /dist` line in `production` to `COPY --from=release ... /app`. Releases are listed at
# https://github.com/kumulicp/kumulicp/releases
#
# FROM base AS release
# ARG APP_VERSION=v1.0.0
# RUN apt-get update && apt-get install -y --no-install-recommends unzip \
#  && rm -rf /var/lib/apt/lists/*
# WORKDIR /release
# ADD https://github.com/kumulicp/kumulicp/releases/download/${APP_VERSION}/kumulicp-${APP_VERSION}.zip ./
# ADD https://github.com/kumulicp/kumulicp/releases/download/${APP_VERSION}/kumulicp-${APP_VERSION}.zip.sha256 ./
# RUN sha256sum -c "kumulicp-${APP_VERSION}.zip.sha256" \
#  && unzip -q "kumulicp-${APP_VERSION}.zip" -d /app \
#  && echo "${APP_VERSION}" > /revision

# --- vendor: composer install ------------------------------------------------------------------
FROM base AS vendor
COPY --from=composer:2.9.5 /usr/bin/composer /usr/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
 && rm -rf /var/lib/apt/lists/*
ENV COMPOSER_HOME=/tmp/composer COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app
COPY --from=source /app /app
# Empty directories are not in git, and Laravel will not boot (composer runs artisan) without them.
RUN mkdir -p storage/framework/views storage/framework/cache/data storage/framework/sessions storage/logs bootstrap/cache
RUN composer install --no-interaction --no-dev --optimize-autoloader

# --- assets: JS build --------------------------------------------------------------------------
FROM node:24.0.0 AS assets
COPY --from=vendor /app /app
WORKDIR /app
RUN npm ci && npm run build
# Assemble only what the app needs to run. Tests, docs, scripts, docker/, node_modules, resources/js and
# friends stay behind in this stage. (`modules/` is empty: it is not in git and nothing installs into it.)
RUN mkdir -p /dist/resources /dist/modules \
 && cp -a artisan composer.json app bootstrap config database public routes storage vendor /dist/ \
 && cp -a resources/views resources/lang /dist/resources/

# --- production: the runtime image -------------------------------------------------------------
FROM base AS production
LABEL org.opencontainers.image.title="kumulicp" \
      org.opencontainers.image.source="https://github.com/kumulicp/kumulicp" \
      org.opencontainers.image.licenses="AGPL-3.0-or-later"

RUN apt-get update && apt-get install -y --no-install-recommends \
    supervisor \
    mariadb-client; \
  rm -rf /var/lib/apt/lists/*

# The scheduler runs as a supervisord program (`artisan schedule:work`), so there is no crond
# and nothing in this image needs root.
COPY supervisord.conf entrypoint.sh /

# How many queue workers supervisord starts (the "long" ones handle $long_running actions).
ENV QUEUE_WORKERS=8 QUEUE_LONG_WORKERS=2

WORKDIR /var/www/html

# configure apache server
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Non-root / compliance hardening for Apache:
#  - listen on an unprivileged port (APACHE_PORT, default 8080) instead of 80
#  - hide the server version
ENV APACHE_PORT=8080
RUN set -eux; \
    echo "ServerName localhost" >> /etc/apache2/apache2.conf; \
    sed -ri 's/^Listen 80$/Listen ${APACHE_PORT}/; s/^(\s*)Listen 443$/\1Listen 8443/' /etc/apache2/ports.conf; \
    sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:${APACHE_PORT}>/' /etc/apache2/sites-available/000-default.conf; \
    sed -ri 's/^ServerTokens .*/ServerTokens Prod/; s/^ServerSignature .*/ServerSignature Off/' /etc/apache2/conf-available/security.conf

RUN a2enmod rewrite

RUN echo "TLS_REQCERT never" >> /etc/ldap/ldap.conf

# The built app (see the `assets` stage for what is in it). Owned by root, so the app cannot rewrite
# its own code; only storage/ and bootstrap/cache are writable by www-data.
COPY --from=assets /dist /var/www/html
COPY --from=source /revision /etc/kumulicp-revision
COPY env.defaults /var/www/html/.env.defaults

# The root filesystem is read-only at runtime. entrypoint.sh builds the real .env in /tmp from the
# container's environment, starting from the non-secret defaults above, and the app finds it through
# the symlink. The symlink must belong to www-data: /var/www/html is sticky and world-writable in the
# php image, and the kernel (fs.protected_symlinks) will not let another user follow a symlink owned
# by root there.
RUN ln -s /tmp/.env /var/www/html/.env \
 && chown -h www-data:www-data /var/www/html/.env

# storage/ is a persistent volume at runtime, which hides whatever is baked into the image. The only
# tracked content in it is storage/app (card images, cities.json), so stash that outside the volume
# and let entrypoint.sh merge it in on start. Everything else in storage/ is created at runtime.
RUN mkdir -p /usr/local/share/kumulicp \
 && mv /var/www/html/storage/app /usr/local/share/kumulicp/storage-app \
 && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Run as www-data (33:33); nothing at runtime needs root and Apache listens on 8080. HOME is /tmp
# (an emptyDir in the chart). With a read-only root filesystem, mount emptyDirs on /tmp,
# /var/run/apache2, /var/lock/apache2 and /var/www/html/bootstrap/cache.
ENV HOME=/tmp
USER 33:33
EXPOSE 8080

# Only used by plain Docker; Kubernetes ignores it and uses its own probes. Generous start period
# because the entrypoint runs migrations before Apache starts.
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
  CMD php -r 'exit(@file_get_contents("http://127.0.0.1:".getenv("APACHE_PORT")."/up") === false ? 1 : 0);'

ENTRYPOINT [ "/entrypoint.sh" ]
CMD ["/usr/bin/supervisord", "-c", "/supervisord.conf"]
