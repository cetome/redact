#!/bin/sh
# Update REDact on shared hosting in one command (IONOS). Upload the release zip next to the
# redact-app folder, then, from that parent folder:
#
#   sh redact-app/deploy/shared-hosting/update.sh redact-v1.0.0-xxxxxxx.zip
#
# Keeps .env and storage/. Overrides: PHP=/usr/bin/php8.4-cli APP=other-folder sh … update.sh <zip>
set -e

PHP="${PHP:-/usr/bin/php8.5-cli}"
APP="${APP:-redact-app}"
ZIP="$1"

[ -n "$ZIP" ] && [ -f "$ZIP" ] || { echo "Usage: sh $APP/deploy/shared-hosting/update.sh <release.zip>"; exit 1; }
[ -f "$APP/artisan" ] && [ -f "$APP/.env" ] || { echo "No $APP/artisan or $APP/.env here: run this from the folder that contains $APP"; exit 1; }

echo "Maintenance mode on"
"$PHP" "$APP/artisan" down --render="errors::503" || true

echo "Unpacking $ZIP"
unzip -q -o "$ZIP" -d "$APP"

cd "$APP"
echo "Permissions (IONOS refuses world-writable PHP files)"
find . -type d -exec chmod 755 {} +
find . -type f -exec chmod 644 {} +
chmod -R u+rwX storage bootstrap/cache
chmod 600 .env

echo "Caches, database, queue"
"$PHP" artisan optimize:clear
"$PHP" artisan migrate --force
"$PHP" artisan optimize
"$PHP" artisan queue:restart

"$PHP" artisan up
echo "Done. Running release:"
cat RELEASE
