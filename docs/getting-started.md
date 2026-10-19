# Getting Started

## Option A: Docker (recommended)

Requirements: Docker Desktop.

```bash
docker compose up -d --build
```

| Service | What it does |
|---|---|
| `web` | nginx on port **8000** (`APP_PORT` to change), reachable from your LAN |
| `app` | PHP 8.4-FPM. On start: `composer install` if `vendor/` is missing, creates `.env`/`APP_KEY` if missing, waits for MySQL, runs `migrate` and `db:seed` (admin user, plus the [default sources](features/sources.md#default-sources-defaultsourcesseeder) on a fresh install) |
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
cp .env.example .env           # APP_NAME=NeboTV is the name shown in the panel
php artisan key:generate
php artisan migrate
php artisan db:seed            # admin / admin123 locally, or ADMIN_USERNAME / ADMIN_PASSWORD from .env (safe to re-run)
composer dev                   # server + queue worker + log tail
php artisan schedule:work      # in another terminal: auto-refresh + session pruning
```

`php artisan serve` only listens on localhost. For TVs on your network, use `php artisan serve --host=0.0.0.0`.

Without a queue worker, source imports stay **Queued** (the Sources page warns about this). Run one with `php artisan queue:work`, or import inline with `php artisan iptv:sync-sources --now`. Where no worker can run (shared hosting), set `QUEUE_SCHEDULED_WORKER=true` and the scheduler processes the queue: see [deployment.md](deployment.md).

## Watching on a TV

1. Add a source (Sources → Add source) and let it import.
2. Create a user and tick the bouquet that holds the channels.
3. Open the user's page and copy the **playlist link** (`/playlist/{token}.m3u`).
4. In the TV's IPTV app choose *Add playlist → M3U URL* and paste it.

The link is built from the address you used to open the panel. If you're on `http://localhost:8000`, the page warns you; open the panel via your computer's LAN IP (e.g. `http://192.168.1.20:8000`) instead.

## Language

The panel defaults to `APP_LOCALE` (`en`). Each admin can switch to Macedonian with the language menu (flags) in the top-right of the header or on the sign-in page; the choice is kept in a browser cookie. Set `APP_LOCALE=mk` in `.env` to make Macedonian the default. Details: [features/localization.md](features/localization.md). Next to it, the sun / moon / monitor buttons choose light, dark or system mode ([features/theme.md](features/theme.md)).

## Default sources

On an empty database, `db:seed` also adds nine sources: two hand-checked Macedonian lists (bouquets **Macedonia TV** and **Macedonia Music**) and public free-to-air lists (World, News, Sports, Movies, Kids, Music, Documentary). They are imported by the queue worker. See [features/sources.md](features/sources.md#default-sources-defaultsourcesseeder).

## Alerts by email

Set `ALERT_MAIL_TO` and the `MAIL_*` variables (Brevo SMTP) in `.env`, then run `php artisan iptv:send-alerts --test`. Details: [features/alerts.md](features/alerts.md#email-with-brevo).

## Sample data

`SampleDataSeeder` creates categories (Sports, Movies, News), 10 channels with public test streams, bouquets **Sports Pack** and **Full Access**, and users `user1`, `user2`, `expired_user` (password `pass123`). Run it once.

## Tests and style

```bash
composer test            # or: docker compose exec app php artisan test
composer lint            # Pint, check only (vendor/bin/pint fixes the files)
composer analyse         # PHPStan / Larastan, level 5
composer check           # all three
npm install && npm run e2e   # browser tests (Playwright)
```

**Browser tests** (`e2e/*.spec.js`, Playwright) cover what PHP tests can't: the bulk selection bar, confirm dialogs, the copy button, theme and language switches, and the whole two-factor sign-in. They use the Google Chrome installed on the machine (no browser download) and start the app themselves on `127.0.0.1:8765` with a throw-away SQLite file (`storage/e2e.sqlite`, created by `e2e/serve.sh`), so they need PHP and `composer install` locally but never touch your database. A failed run leaves a trace in `test-results/` (`npx playwright show-trace`).

**GitHub Actions** (`.github/workflows/ci.yml`) run the PHP tests, Pint + PHPStan, the browser tests, the MySQL migration check and the relay tests on every push to `main` and every pull request.

Tests always run on in-memory SQLite: `phpunit.xml` forces it, and `Tests\TestCase::beforeRefreshingDatabase()` refuses to wipe any other database.
