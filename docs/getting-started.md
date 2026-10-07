# Getting Started

## Requirements

- PHP 8.3+ (developed on 8.4)
- Composer
- MySQL / MariaDB (default `.env`), database `iptv_service`
- Node.js (only for Vite; the admin UI itself uses static `public/css/admin.css`)

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
# create the MySQL database `iptv_service`, adjust DB_* in .env
php artisan migrate
php artisan db:seed                            # runs AdminSeeder: admin / admin123 (safe to re-run)
php artisan db:seed --class=SampleDataSeeder   # optional demo data (run once)
```

Set `APP_URL` correctly (e.g. `http://localhost:8000`): play URLs inside generated playlists are built with `route()`.

## Run

```bash
composer dev          # server + queue listener + log tail + vite
# or
php artisan serve
```

- Scheduler (prunes stale stream sessions): `php artisan schedule:work`
- Admin panel: `http://localhost:8000/login` → `/admin/dashboard`
- Playlist: `http://localhost:8000/m3u?username=user2&password=pass123`

## Sample data (SampleDataSeeder)

- Categories: Sports, Movies, News
- 10 channels pointing at public test streams
- Bouquets: **Sports Pack** (sports channels), **Full Access** (all)
- Users (password `pass123`): `user1` (Sports Pack, 1 connection), `user2` (Full Access, 2 connections), `expired_user` (expired yesterday)

## Tests and style

```bash
composer test         # Pest, sqlite in-memory
vendor/bin/pint       # currently broken, see known-issues
```

Tests live in `tests/Feature` and use `RefreshDatabase`, `User::factory()` (states `admin()`, `disabled()`, `expired()`) and the `subscribedChannel($user, [...])` helper from `tests/Pest.php`.
