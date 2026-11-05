#!/bin/sh
# Update REDact on shared hosting in one command (IONOS). Upload the release zip (and the content
# pack zip, when there is a new one) next to the redact-app folder, then, from that parent folder:
#
#   sh redact-app/deploy/shared-hosting/update.sh redact-v1.0.0-xxxxxxx.zip [redact-content-xxxxxxx.zip]
#
# Keeps .env and storage/. The content pack goes where REDACT_CONTENT_PATH in .env says (default
# ../redact-content); the previous one stays next to it as <folder>-old until the next pack update.
# Overrides: PHP=/usr/bin/php8.4-cli APP=other-folder sh … update.sh <zip> [<pack zip>]
# If a step fails, the script stops there and names it; the site stays in maintenance mode.
set -e

PHP="${PHP:-/usr/bin/php8.5-cli}"
APP="${APP:-redact-app}"
ZIP="$1"
PACK="$2"
ROOT=$(pwd)
STEP="checks"
DOWN=
trap 'code=$?; [ "$code" -eq 0 ] || { echo "FAILED at: $STEP (exit $code)"; [ -z "$DOWN" ] || echo "The site is still in maintenance mode. Fix the cause and run this script again, or bring the site back with: cd $ROOT/$APP && $PHP artisan up"; }' EXIT

[ -n "$ZIP" ] && [ -f "$ZIP" ] || { echo "Usage: sh $APP/deploy/shared-hosting/update.sh <release.zip> [<content pack.zip>]"; exit 1; }
[ -f "$APP/artisan" ] && [ -f "$APP/.env" ] || { echo "No $APP/artisan or $APP/.env here: run this from the folder that contains $APP"; exit 1; }
# E-mail addresses live in .env only, never in the code: say which ones are missing (the update goes on).
for KEY in MAIL_FROM_ADDRESS REDACT_CONTACT_TO REDACT_SECURITY_CONTACT; do
    grep -Eq "^$KEY=[\"']?[^[:space:]\"'#]+@" "$APP/.env" || echo "Warning: $KEY is not set in $APP/.env (an e-mail address)"
done
if [ -n "$PACK" ]; then
    [ -f "$PACK" ] || { echo "No content pack zip $PACK"; exit 1; }
    unzip -l "$PACK" | grep -q 'pack\.json$' || { echo "$PACK is not a content pack (no pack.json)"; exit 1; }
    # REDACT_CONTENT_PATH from .env (quotes, a trailing comment and Windows line ends removed), relative to the app.
    CONTENT=$(sed -n 's/^REDACT_CONTENT_PATH=//p' "$APP/.env" | tail -n 1 | tr -d '\r' | sed 's/[[:space:]]#.*$//; s/[[:space:]]*$//' | tr -d "\"'")
    [ -n "$CONTENT" ] || CONTENT=../redact-content
    case "$CONTENT" in /*) ;; *) CONTENT="$APP/$CONTENT" ;; esac
fi

echo "Maintenance mode on"
STEP="maintenance mode"
"$PHP" "$APP/artisan" down --render="errors::503" || true
DOWN=1

echo "Unpacking $ZIP"
STEP="unpacking $ZIP"
unzip -q -o "$ZIP" -d "$APP"

if [ -n "$PACK" ]; then
    echo "Content pack $PACK into $CONTENT (the previous one kept as $CONTENT-old)"
    STEP="content pack $PACK"
    rm -rf "$CONTENT.new"
    unzip -q "$PACK" -d "$CONTENT.new"
    # The zip holds the pack itself or one folder with it (redact-content/).
    NEW=$(dirname "$(find "$CONTENT.new" -maxdepth 2 -name pack.json | head -n 1)")
    rm -rf "$CONTENT-old"
    [ ! -d "$CONTENT" ] || mv "$CONTENT" "$CONTENT-old"
    mv "$NEW" "$CONTENT"
    rm -rf "$CONTENT.new"
    find "$CONTENT" -type d -exec chmod 755 {} +
    find "$CONTENT" -type f -exec chmod 644 {} +
fi

cd "$APP"
echo "Permissions (IONOS refuses world-writable PHP files)"
STEP="permissions"
find . -type d -exec chmod 755 {} +
find . -type f -exec chmod 644 {} +
chmod -R u+rwX storage bootstrap/cache
chmod 600 .env

echo "Caches, database"
STEP="optimize:clear"
"$PHP" artisan optimize:clear
STEP="migrate"
"$PHP" artisan migrate --force
STEP="optimize"
"$PHP" artisan optimize

STEP="up"
"$PHP" artisan up
DOWN=

# Only tells running queue workers to load the new code: not worth keeping the site down for.
"$PHP" artisan queue:restart || echo "Warning: queue:restart failed, the site is up anyway"
echo "Done. Running release:"
cat RELEASE
