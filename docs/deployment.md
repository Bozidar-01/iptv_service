# Deployment: shared hosting (e.g. Hostinger)

Shared hosting (Hostinger Premium/Business/Cloud, cPanel-style hosts) has PHP, MySQL, SSH and cron, but no long-running processes. The app works there because:

- `/play` only redirects to the provider, so video never passes through the hosting.
- Imports stream the playlist line by line and write 500 rows at a time, so memory stays low.
- No Node build is needed: the panel's CSS is plain `public/css/admin.css`.
- The queue worker can run from the scheduler (`QUEUE_SCHEDULED_WORKER=true`), so a **single cron job** handles imports, auto-refresh and session pruning.

For a VPS, use the Docker stack instead ([getting-started.md](getting-started.md)).

## 1. Upload the code

Put the project **outside** `public_html`, e.g. with SSH + git, or hPanel → Git:

```bash
cd ~/domains/yourdomain.com
git clone <repo-url> nebotv
```

Point the web root at `public/` (Laravel must not serve the project root):

```bash
cd ~/domains/yourdomain.com && rm -rf public_html && ln -s nebotv/public public_html
```

## 2. PHP and database

- hPanel → Advanced → PHP Configuration: **PHP 8.4** (8.3 minimum).
- hPanel → Databases: create a MySQL database and user; note name, user, password.

## 3. Install and configure

```bash
cd ~/domains/yourdomain.com/nebotv
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com
DB_HOST=localhost
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=900
QUEUE_SCHEDULED_WORKER=true
```

Then:

```bash
php artisan migrate --force
php artisan db:seed --force      # creates admin / admin123: change the password right away
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Re-run the `*:cache` commands after every `.env` change or code update.

## 4. Cron (one job)

hPanel → Advanced → Cron Jobs → every minute (`* * * * *`):

```
cd ~/domains/yourdomain.com/nebotv && php artisan schedule:run >> /dev/null 2>&1
```

If hPanel asks for a PHP binary, use the full path it suggests (e.g. `/usr/bin/php` or `/opt/alt/php84/usr/bin/php`) instead of `php`.

This runs everything in `routes/console.php`:

| Task | When |
|---|---|
| `queue:work --stop-when-empty --tries=1 --timeout=600 --max-time=240` (only with `QUEUE_SCHEDULED_WORKER=true`) | every minute, never two at once (15-minute lock), in the background |
| `iptv:sync-sources --due` | hourly |
| `model:prune` for `ActiveSession` | every 15 minutes |

A newly added source starts importing within about a minute.

## 5. Updating

```bash
cd ~/domains/yourdomain.com/nebotv
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## Limits to expect

- **Very large playlists** (roughly 50k–100k entries) may be stopped by the host's CPU/process limits. The source is then marked **Failed** with "The import was stopped before it finished." (`SyncSource::failed`, after `DB_QUEUE_RETRY_AFTER`). Channels written so far are kept and nothing is deleted, because removing vanished channels is the last step. Tick **Skip VOD** to shrink the playlist, or run that source over SSH: `php artisan iptv:sync-sources <id> --now`.
- **HTTPS:** enable the free SSL certificate in hPanel; playlist and play links use the request host, so they follow your domain.
- **Terms of service:** hosts forbid distributing content you don't have rights to. Only use provider streams you are licensed to redistribute.
