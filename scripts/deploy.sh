#!/usr/bin/env bash
# Updates a server to a commit and restarts nothing that needs restarting (shared hosting: PHP is
# started per request). Used by the "deploy" job in .github/workflows/ci.yml, which sends this file
# over SSH, so the script that runs is always the one from the commit being deployed:
#
#   ssh user@host "bash -s -- /path/to/project <commit>" < scripts/deploy.sh
#
# By hand, on the server:   bash scripts/deploy.sh "$PWD"      (latest main)
#
# Steps: stop if files were changed on the server → fetch the commit → back up the database →
# composer install → migrate → rebuild caches → backup check → open /login to see it answers.
# Stops at the first error. Migrations can't be undone by git: the dump from before is kept
# (the last 5 in ~/deploy-backups) and the steps to go back are printed on failure.
set -euo pipefail

APP_DIR="${1:?usage: deploy.sh <project folder> [commit]}"
TARGET="${2:-origin/main}"

cd "$APP_DIR"
test -f artisan || { echo "STOP: no artisan file in $APP_DIR"; exit 1; }

OLD=$(git rev-parse --short HEAD)
BACKUP=""

on_error() {
    echo
    echo "!! The update stopped. The site is on a mix of old and new: do not leave it like this."
    echo "!! Version before: $OLD"
    [ -n "$BACKUP" ] && echo "!! Database backup from before: $BACKUP" || true
    echo "!! Go back:  cd $APP_DIR && php artisan migrate:rollback --step=<migrations just run> --force; git reset --hard $OLD; php artisan config:cache; php artisan route:cache; php artisan view:cache"
    [ -n "$BACKUP" ] && echo "!! Or restore the dump:  gunzip -c $BACKUP | mysql -u DBUSER -p DBNAME" || true
}
trap on_error ERR

env_value() {
    grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//" || true
}

echo ">> Folder: $APP_DIR"
echo ">> Version now: $OLD"

# 1. Files changed on the server would make the update fail half way
if [ -n "$(git status --porcelain)" ]; then
    echo "STOP: files were changed on the server:"
    git status --short
    trap - ERR
    exit 1
fi

# 2. The new code (fast-forward only: never rewrites history on the server)
git fetch --quiet origin main
git merge --ff-only --quiet "$TARGET"
NEW=$(git rev-parse --short HEAD)
echo ">> Version new: $NEW"

# 3. Back up the database first (login details are read from .env)
if [ "$(env_value DB_CONNECTION)" = "mysql" ] && command -v mysqldump > /dev/null; then
    mkdir -p ~/deploy-backups
    BACKUP=~/deploy-backups/before-$NEW-$(date +%Y%m%d-%H%M%S).sql.gz
    DB_HOST="$(env_value DB_HOST)"; DB_PORT="$(env_value DB_PORT)"
    MYSQL_PWD="$(env_value DB_PASSWORD)" mysqldump -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" \
        -u "$(env_value DB_USERNAME)" --single-transaction --no-tablespaces "$(env_value DB_DATABASE)" | gzip > "$BACKUP"
    test -s "$BACKUP"
    echo ">> Backup: $BACKUP ($(du -h "$BACKUP" | cut -f1))"
    # keep the five newest
    ls -1t ~/deploy-backups/before-*.sql.gz | tail -n +6 | while read -r old; do rm -f "$old"; done
else
    echo ">> No database backup (not MySQL, or no mysqldump here)"
fi

# 4. Dependencies, database, caches
composer install --no-dev --optimize-autoloader --no-interaction --quiet
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 5. The new backup command works (not fatal: needs mysqldump)
php artisan iptv:backup || echo "NOTE: iptv:backup failed (no mysqldump on this host?). The site still works."

# 6. The site answers
APP_URL="$(env_value APP_URL)"
if [ -n "$APP_URL" ] && command -v curl > /dev/null; then
    CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "${APP_URL%/}/login" || true)
    if [ "$CODE" != "200" ]; then
        echo "STOP: ${APP_URL%/}/login answered $CODE, not 200"
        false
    fi
    echo ">> ${APP_URL%/}/login answers 200"
fi

trap - ERR
echo ">> Done: $OLD -> $NEW"
