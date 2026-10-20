#!/bin/sh
set -eu
cd /var/www/html
: "${APP_KEY:?Supply the existing application key}"
: "${DB_HOST:?Supply the approved database endpoint}"
case "${PORT:-10000}" in *[!0-9]*|'') echo 'Invalid PORT' >&2; exit 1;; esac
sed -i "s/^Listen .*/Listen ${PORT:-10000}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT:-10000}>/" /etc/apache2/sites-available/000-default.conf
mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache
php artisan storage:link --force
php artisan config:cache
php artisan view:cache
# Schema changes, imports and seeders are NEVER run on startup.
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
