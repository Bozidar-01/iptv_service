#!/bin/sh
# Starts the app for the browser tests (playwright.config.js): a throw-away SQLite database
# with the admin and the sample data, served on 127.0.0.1:8765. Never touches the real database.
set -e
cd "$(dirname "$0")/.."

export APP_ENV=local
export APP_DEBUG=false
export APP_KEY="${APP_KEY:-base64:$(openssl rand -base64 32)}"
export DB_CONNECTION=sqlite
export DB_DATABASE="$PWD/storage/e2e.sqlite"
export DB_URL=""
export SESSION_DRIVER=file
export CACHE_STORE=file
export QUEUE_CONNECTION=sync
export ADMIN_USERNAME=admin
export ADMIN_PASSWORD=e2e-password
export BACKUP_DISK=local

rm -f "$DB_DATABASE" && touch "$DB_DATABASE"
php artisan migrate --force --no-interaction > /dev/null
php artisan db:seed --class=AdminSeeder --force --no-interaction > /dev/null
php artisan db:seed --class=SampleDataSeeder --force --no-interaction > /dev/null

exec php artisan serve --host=127.0.0.1 --port=8765 --no-reload
