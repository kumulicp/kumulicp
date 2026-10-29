#!/bin/bash
set -eu

html_dir=/var/www/html
APP_DEBUG=false
if [ "${APP_ENV:-production}" != 'production' ]; then
    APP_DEBUG=true
fi

# /var/www/html/.env is a symlink to /tmp/.env because the root filesystem is read-only. Start from the
# image's non-secret defaults on every container start, so a restart never appends a second copy.
cp "$html_dir/.env.defaults" /tmp/.env

# Adds variables only if present. The app won't work if required variables are missing, but atleast now it won't be mad about missing less important variables
add_var() (
    set +u
    var=$1
    # Skip unset or empty variables: an empty LDAP_TLS= would reach Laravel as '' instead of a boolean.
    [ -n "${!var}" ] || return 0
    if [ "${2:-}" = quote ]; then
        echo "$var=\"${!var}\"" >> /tmp/.env
    else
        echo "$var=${!var}" >> /tmp/.env
    fi
)
add_var APP_KEY
add_var APP_ENV
add_var APP_DEBUG
add_var LOG_CHANNEL
add_var APP_LOCALE

add_var SUPPORT_EMAIL
add_var ERROR_EMAIL

add_var ACCOUNTMANAGER_DRIVER
add_var PROVIDER

add_var DB_HOST
add_var DB_PORT
add_var DB_DATABASE
add_var DB_USERNAME
add_var DB_PASSWORD

add_var LDAP_HOST
add_var LDAP_DOMAIN
add_var LDAP_USERNAME quote
add_var LDAP_PASSWORD
add_var LDAP_BASE_DN quote
add_var LDAP_TLS

add_var BILLING_DRIVER
add_var STRIPE_KEY
add_var STRIPE_SECRET

add_var MAIL_MAILER
add_var MAIL_HOST
add_var MAIL_PORT
add_var MAIL_USERNAME
add_var MAIL_PASSWORD
add_var MAIL_ENCRYPTION
add_var MAIL_FROM_ADDRESS
add_var MAIL_FROM_NAME quote

add_var ERROR_MAIL_MAILER
add_var ERROR_MAIL_HOST
add_var ERROR_MAIL_PORT
add_var ERROR_MAIL_USERNAME
add_var ERROR_MAIL_PASSWORD
add_var ERROR_MAIL_ENCRYPTION
add_var ERROR_MAIL_FROM_ADDRESS
add_var ERROR_MAIL_FROM_NAME quote

add_var REGISTRAR_DRIVER
add_var NC_API_USER
add_var NC_API_KEY
add_var NC_USERNAME
add_var NC_CLIENT_IP
add_var NC_URL

# storage/ is a volume, so merge the image's tracked files (card images, cities.json) into it.
# -u: don't overwrite files in the volume that are newer than the image's.
# cache/data must exist before `cache:clear` (the file cache store only creates it lazily).
mkdir -p \
    "$html_dir/storage/app" \
    "$html_dir/storage/framework/cache/data" \
    "$html_dir/storage/framework/sessions" \
    "$html_dir/storage/framework/views" \
    "$html_dir/storage/logs"
cp -ru /usr/local/share/kumulicp/storage-app/. "$html_dir/storage/app/"

# Wait for the database (up to ~2 minutes) so a cold start next to it doesn't fail the migrations.
# MYSQL_PWD keeps the password off the command line.
export MYSQL_PWD="${DB_PASSWORD:-}"
for _ in $(seq 60); do
    mariadb-admin ping --silent -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "${DB_USERNAME:-root}" && break
    sleep 2
done
unset MYSQL_PWD

php artisan cache:clear
php artisan config:clear
php artisan migrate --force
php artisan queue:restart
# The world data seeder (run by `upgrade`) refuses to start below 512M; the php.ini default is 128M.
php -d memory_limit=512M artisan upgrade

# Only possible (and only needed) when started as root, e.g. docker run --user 0.
# As www-data the volumes are expected to be writable already (k8s fsGroup).
if [ "$(id -u)" = 0 ]; then
    chown -R www-data:www-data "$html_dir/storage"
fi

exec "$@"
