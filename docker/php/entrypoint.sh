#!/bin/sh
set -e

cd /var/www/html

# Fresh checkout without vendor/ (or a bind mount hiding the image's copy)
if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist --no-progress
fi

if [ ! -f .env ]; then
    cp .env.example .env
fi

if ! grep -q '^APP_KEY=base64' .env; then
    php artisan key:generate --force
fi

# Only the main app container runs migrations; queue/scheduler just wait for the DB
until php -r 'new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));' 2>/dev/null; do
    echo "Waiting for database..."
    sleep 2
done

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
    # Creates the first admin (ADMIN_USERNAME / ADMIN_PASSWORD, admin / admin123 locally); safe to repeat
    php artisan db:seed --force
fi

exec "$@"
