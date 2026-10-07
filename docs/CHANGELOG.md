# Changelog

Format: newest first. Each entry: date, short description, files touched. Add new entries under **Unreleased** as part of every change.

## Unreleased

### 2026-10-07 — v1: M3U sources, admin redesign, Docker
- **Sources:** import provider playlists by URL or upload (`sources` table, `Source` model, `SourceService`, `SourceSyncService`, `M3uParser`, `SyncSource` job, `iptv:sync-sources` command scheduled hourly with `--due`). Channels are upserted by `(source_id, external_key)`, keep the admin's enabled/disabled choice, vanished ones are deleted. Optional VOD skipping, auto-add to a (new) bouquet, daily auto-refresh.
- **Channels:** new columns `source_id`, `external_key`, `tvg_id`, `sort_order`; URLs up to 2048 chars. List with search/filters and bulk enable/disable/add to bouquet/remove from bouquet/delete, including "all matching".
- **Bouquets:** form adds whole categories instead of a checkbox per channel (scales to big lists).
- **Users:** per-user playlist link `/playlist/{token}.m3u` (`users.playlist_token`, regenerable), user page with copy button, TV setup steps and warnings, search/status filters, can't delete yourself, unticking all bouquets now clears them, create/update redirect to the user page.
- **Playlist:** channels de-duplicated across bouquets, ordered by category/source order/name, `tvg-id` from the source, play URLs carry the stream extension (`.m3u8`, `.ts`…), playback tokens renewed when under 7 days left.
- **Dashboard:** real stats (active subscribers, enabled channels, watching now, plays 24 h), source status, expiring subscriptions; removed fake trend labels.
- **Redesign:** new `public/css/admin.css` design system (light/dark, responsive drawer), `<x-icon>` component, shared flash/errors/pagination/status partials, clear labels on every page, new login page. Removed `welcome.blade.php` and the unused Tailwind form partials.
- **Docker:** `Dockerfile`, `docker-compose.yml` (nginx, PHP-FPM, MySQL 8.4, queue worker, scheduler), `docker/` config, `.dockerignore`. The app container migrates and seeds on start.
- **Test safety:** `phpunit.xml` forces SQLite via `<server>` too (Docker env vars otherwise won), and `Tests\TestCase::beforeRefreshingDatabase()` refuses to wipe any non-memory database.
- Fixed the channels migration `down()` for MySQL (drop FK before the unique index).
- `.env.example`: `DB_QUEUE_RETRY_AFTER=900`.
- Tests: 101 (parser, sync, sources admin, bulk actions, users, playlist link, ordering/dedupe, extensions), run on SQLite and MySQL 9.7.
- Files: `app/Models/{Source,Channel,User,ActiveSession}.php`, `app/Services/*`, `app/Jobs/SyncSource.php`, `app/Console/Commands/SyncSources.php`, `app/Http/Controllers/**`, `routes/*.php`, `database/migrations/2026_10_07_*`, `resources/views/**`, `public/css/admin.css`, `Dockerfile`, `docker-compose.yml`, `docker/**`, `.dockerignore`, `phpunit.xml`, `tests/**`, `README.md`, `docs/**`.

### 2026-10-07 — .gitignore additions
- Ignore IDE helper files, `/coverage`, `.claude/settings.local.json` and `CLAUDE.local.md`. `CLAUDE.md` and `.claude/skills/` stay tracked.
- Files: `.gitignore`

### 2026-10-07 — Bug fixes and first test suite
- `/m3u` no longer crashes: added the `User::accessTokens()` relation.
- Expired accounts now get `403 Account expired` from `/m3u`; disabled accounts get `403 Account disabled`.
- M3U output is escaped: `"` becomes `'` inside attributes, and line breaks become spaces.
- Connection limit: the same-channel exception only applies to sessions active in the last 5 minutes. `started_at` is kept while a session stays active and reset after it goes stale. Stale `active_sessions` rows are pruned every 15 minutes (`ActiveSession` is `Prunable`, scheduled in `routes/console.php`). The timeout is `StreamService::SESSION_TIMEOUT_MINUTES`.
- Channels can be deactivated: the create/edit forms send a hidden `is_active=0`.
- Bouquet create/edit and the categories page now show validation errors (new partial `admin/partials/errors`).
- Removed unimplemented resource routes (`show` everywhere; `create`/`edit` for categories).
- `UserFactory` matches the schema, with `admin()`, `disabled()` and `expired()` states. `DatabaseSeeder` now calls `AdminSeeder`, which is idempotent. Fixed the `SampleDataSeeder` namespace.
- Tests: enabled `RefreshDatabase` and added the `subscribedChannel()` helper in `tests/Pest.php`. 47 feature tests cover auth, playlist, play/limits/pruning, channel admin, validation display and seeders.
- Files: `app/Models/User.php`, `app/Models/ActiveSession.php`, `app/Services/TokenService.php`, `app/Services/StreamService.php`, `app/Http/Controllers/M3uController.php`, `routes/web.php`, `routes/console.php`, `resources/views/admin/{channels/create,channels/edit,bouquets/create,bouquets/edit,categories/index,partials/errors}.blade.php`, `database/factories/UserFactory.php`, `database/seeders/*`, `tests/**`.

### 2026-10-07 — Documentation baseline
- Added `CLAUDE.md`, `docs/` (architecture, data model, routes, features, known issues) and Claude skills in `.claude/skills/` (`update-docs`, `add-admin-resource`, `write-tests`).

## Pre-docs (reconstructed from the code, ~2026-04)

- Laravel 13 skeleton; users table changed to `username`/`status`/`role`/`expiration_date`/`max_connections`.
- Schema: categories, channels, bouquets, pivots, access_tokens, active_sessions, stream_logs.
- Services: User, Channel, Bouquet, Token, Stream.
- Admin auth (admin-only login).
- Admin panel: dashboard, users (with status toggle), channels, categories, bouquets, activity logs; custom dark theme `public/css/admin.css`.
- Public endpoints: `/m3u` playlist and `/play/{channel_id}/{token}` redirect with access checks, connection limit and logging.
- Seeders: `AdminSeeder`, `SampleDataSeeder`.
