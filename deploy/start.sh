#!/bin/sh
set -eu
: "${APP_KEY:?Set a persistent APP_KEY in Render before deploying}"
sed -i 's/^Listen 80$/Listen 10000/' /etc/apache2/ports.conf
php artisan config:cache
php artisan view:cache
# Opt-in for the first deployment to a dedicated, empty demonstration database.
# Switch this off immediately after a successful deploy.
if [ "${DEMO_RUN_MIGRATIONS:-false}" = "true" ]; then
    [ "${DEMO_MODE:-false}" = "true" ] || { echo 'Demo migrations require DEMO_MODE=true'; exit 1; }
    php artisan migrate --force --no-interaction
fi
exec apache2-foreground
