#!/usr/bin/env bash
# Sets Hostinger-appropriate permissions on the deployed app.
# Usage: bash set-permissions.sh /path/to/public_html
set -euo pipefail

APP="${1:-$(pwd)}"

if [ ! -f "$APP/index.php" ]; then
    echo "ERROR: $APP does not look like the app root (no index.php)" >&2
    exit 1
fi

find "$APP" -type d -exec chmod 755 {} +
find "$APP" -type f -exec chmod 644 {} +
find "$APP/bin" -type f -name '*.php' -exec chmod 755 {} +

if [ -d "$APP/storage" ]; then
    chmod -R 775 "$APP/storage"
fi

if [ -f "$APP/.env" ]; then
    chmod 600 "$APP/.env"
else
    echo "NOTE: no .env yet — create it from .env.example, then: chmod 600 .env"
fi

echo "Permissions set for $APP"
