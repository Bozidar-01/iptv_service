# Getting Started

## Option A: Docker (recommended)

Requirements: Docker Desktop.

```bash
docker compose up -d --build
```

| Service | What it does |
|---|---|
| `web` | nginx on port **8000** (`APP_PORT` to change), reachable from your LAN |
| `app` | PHP 8.4-FPM. On start: `composer install` if `vendor/` is missing, creates `.env`/`APP_KEY` if missing, waits for MySQL, runs `migrate` and `db:seed` (admin user) |
| `queue` | `php artisan queue:work --timeout=600`: runs source imports |
| `scheduler` | `php artisan schedule:work`: hourly auto-refresh of sources, pruning of stale sessions |
| `db` | MySQL 8.4, data in the `db-data` volume. From your Mac: `127.0.0.1:3307`, user `iptv` / `iptv`, database `iptv_service` |

The project folder is bind-mounted, so code changes apply immediately (no rebuild). Rebuild only after changing `Dockerfile` or `docker/`.

Compose sets `DB_*`, `QUEUE_CONNECTION` and `DB_QUEUE_RETRY_AFTER` as real environment variables. They override `.env`, which can keep pointing at a local MySQL for non-Docker use.

Common commands:

```bash
docker compose exec app php artisan test                          # tests (forced to in-memory SQLite)
docker compose exec app php artisan db:seed --class=SampleDataSeeder
docker compose exec app php artisan iptv:sync-sources --now       # import all sources right now
docker compose logs -f queue                                      # watch imports
docker compose down                                               # stop (data is kept)
docker compose down -v                                            # stop and DELETE the database
```

## Option B: Without Docker

Requirements: PHP 8.3+ with `pdo_mysql`, Composer, MySQL/MariaDB with database `iptv_service`.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed            # admin / admin123 (safe to re-run)
composer dev                   # server + queue worker + log tail + vite
php artisan schedule:work      # in another terminal: auto-refresh + session pruning
```

`php artisan serve` only listens on localhost. For TVs on your network, use `php artisan serve --host=0.0.0.0`.

Without a queue worker, source imports stay **Queued** (the Sources page warns about this). Run one with `php artisan queue:work`, or import inline with `php artisan iptv:sync-sources --now`.

## Watching on a TV

1. Add a source (Sources → Add source) and let it import.
2. Create a user and tick the bouquet that holds the channels.
3. Open the user's page and copy the **playlist link** (`/playlist/{token}.m3u`).
4. In the TV's IPTV app choose *Add playlist → M3U URL* and paste it.

The link is built from the address you used to open the panel. If you're on `http://localhost:8000`, the page warns you; open the panel via your computer's LAN IP (e.g. `http://192.168.1.20:8000`) instead.

## Sample data

`SampleDataSeeder` creates categories (Sports, Movies, News), 10 channels with public test streams, bouquets **Sports Pack** and **Full Access**, and users `user1`, `user2`, `expired_user` (password `pass123`). Run it once.

## Tests and style

```bash
composer test            # or: docker compose exec app php artisan test
vendor/bin/pint          # currently broken locally, see known-issues
```

Tests always run on in-memory SQLite: `phpunit.xml` forces it, and `Tests\TestCase::beforeRefreshingDatabase()` refuses to wipe any other database.
