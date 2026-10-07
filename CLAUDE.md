# IPTV Service

Laravel 13 (PHP 8.3+) IPTV middleware: an admin panel to manage users, channels, categories and bouquets (subscription packs), plus public endpoints that serve per-user M3U playlists and token-gated stream redirects.

## Documentation — always keep it current

Docs live in [`docs/`](docs/README.md). **Every change that adds, removes or alters behaviour must update the docs in the same task** — no exceptions, even for small fixes. Use the `update-docs` skill. At minimum:

- Add an entry to [`docs/CHANGELOG.md`](docs/CHANGELOG.md) under `Unreleased`.
- Update the relevant feature doc (`docs/features/*.md`), [`docs/data-model.md`](docs/data-model.md) for schema changes, [`docs/routes.md`](docs/routes.md) for route changes.
- If you fix something listed in [`docs/known-issues.md`](docs/known-issues.md), remove it there; if you notice a new problem you are not fixing, add it.

## Commands

```bash
composer dev                 # serve + queue + pail logs + vite, concurrently
php artisan serve            # app only, http://localhost:8000
php artisan migrate:fresh --seed                        # admin / admin123
php artisan db:seed --class=SampleDataSeeder            # categories, channels, bouquets, user1/user2/expired_user (pass123)
composer test                # Pest (sqlite :memory:); all tests must pass before finishing a task
vendor/bin/pint              # code style
```

Local DB is MySQL `iptv_service` (see `.env`). Tests use sqlite in-memory (phpunit.xml).

## Architecture

- **Controllers stay thin; logic lives in `app/Services/`.** Services are injected via constructor (`protected $xService;` assigned in `__construct`). Follow this pattern.
  - `UserService`, `ChannelService` (channels **and** categories), `BouquetService`, `TokenService` (access tokens + M3U generation), `StreamService` (access checks, session tracking, logging).
- Validation is inline `$request->validate([...])` in controllers (no FormRequests yet).
- Models use PHP attributes for mass assignment: `#[Fillable([...])]`, `#[Hidden([...])]`, and a `casts()` method.
- Admin routes: `routes/web.php`, group `auth` + prefix `admin` + name `admin.`, `Route::resource(...)`. Redirect back to `admin.<resource>.index` with `->with('success', '...')`.
- Views: Blade, `@extends('layouts.admin')`, styled by hand-written **`public/css/admin.css`** (dark theme, CSS variables like `--accent`, classes like `canvas-card`, `data-table`, `action-btn btn-electric`, `chip chip-success`, `input-field`). Vite/Tailwind are installed but **not** used by the admin layout. The `*/partials/_form.blade.php` files use Tailwind classes and are currently unused.
- Users table has no email: auth is by `username`. `role` = `admin|user`, `status` = `active|disabled`. Only admins can log into the panel.

## Domain flow (short)

1. Client fetches `GET /m3u?username=&password=` → user validated → reuses/creates an `access_tokens` row (1 month) → M3U with one `/play/{channel_id}/{token}` URL per active channel in the user's bouquets.
2. `GET /play/{channel_id}/{token}` → token check → `StreamService::isAllowed` (active, not expired, channel active, channel in a bouquet, `max_connections` vs `active_sessions` within 5 min) → logs to `stream_logs`, upserts `active_sessions` → 302 to the channel's real `stream_url`.

Details: [`docs/features/`](docs/features/).

## Gotchas

- Check `docs/known-issues.md` before debugging.
- Stale `active_sessions` are pruned by the scheduler (`php artisan schedule:work` locally).
- `vendor/bin/pint` is currently broken (missing build); run `composer install` to restore it.
- Migrations are ordered by the `_1_`…`_7_` suffix in their filenames; keep FK order when adding tables.

## Skills

- `update-docs` — how to update docs/changelog after any change.
- `add-admin-resource` — scaffold a new admin CRUD (migration → model → service → controller → routes → views → docs).
- `write-tests` — Pest feature test conventions for this project.
