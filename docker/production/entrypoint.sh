#!/bin/bash
set -eu

html_dir=/var/www/html
# /var/www/html/.env is a symlink to /tmp/.env because the root filesystem is read-only. Start from the
# image's non-secret defaults on every container start, so a restart never appends a second copy.
cp "$html_dir/.env.defaults" /tmp/.env

# Everything else is configured through the container's environment: Laravel never lets a line in .env
# override a variable that is already set there, and Apache, the queue workers and the scheduler all
# inherit it. The only thing derived here is the debug default, which APP_DEBUG in the environment overrides.
if [ "${APP_ENV:-production}" = 'production' ]; then
    echo 'APP_DEBUG=false' >> /tmp/.env
else
    echo 'APP_DEBUG=true' >> /tmp/.env
fi

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
