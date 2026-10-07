# Changelog

Format: newest first. Each entry: date, short description, files touched. Add new entries under **Unreleased** as part of every change.

## Unreleased

### 2026-10-07 — Language flags and light/dark mode switch
- The language picker is now a menu with flag images (`public/images/flags/{gb,mk}.svg`) instead of a plain select. `config('app.locales')` entries are now `['name' => …, 'flag' => …]`.
- New light / dark / system switch next to it (sidebar and sign-in page). The choice is stored in an unencrypted `theme` cookie, rendered as `<html data-theme>` by `App\Support\Theme`, applied instantly by JS, with `POST /theme` (`ThemeController`) as the no-JS fallback. The dark CSS tokens now apply for OS dark mode (unless light is forced) and for `data-theme="dark"`.
- Both controls live in the new `partials/preferences` partial (replaces `partials/locale-switcher`). New icons: `sun`, `moon`, `monitor`, `chevron-down`. Macedonian strings: Theme, Light, Dark, System.
- Tests: `ThemeTest` (default, cookie set/cleared, rendering, invalid values) and a flag test in `LocaleTest`. 120 tests.
- Docs: new `docs/features/theme.md`; localization, routes, architecture, admin-panel, authentication, getting-started; CLAUDE.md and skills mention checking both themes.
- Files: `config/app.php`, `bootstrap/app.php`, `routes/web.php`, `app/Support/Theme.php`, `app/Http/Controllers/ThemeController.php`, `resources/views/partials/preferences.blade.php`, `resources/views/{layouts/admin,auth/login}.blade.php`, `resources/views/components/icon.blade.php`, `public/css/admin.css`, `public/images/flags/*`, `lang/mk.json`, `tests/Feature/{ThemeTest,LocaleTest}.php`, `docs/**`, `CLAUDE.md`, `.claude/skills/*`.

### 2026-10-07 — Shared hosting support
- New `QUEUE_SCHEDULED_WORKER` env var (`config('queue.scheduled_worker')`, default `false`). When `true`, the scheduler runs `queue:work --stop-when-empty --tries=1 --timeout=600 --max-time=240` every minute (in the background, never overlapping, 15-minute lock), so one `schedule:run` cron job is enough on hosting without a long-running worker. Docker keeps its `queue` container and leaves it off.
- `SyncSource::failed()`: a source whose import job was killed or timed out is now marked **Failed** ("The import was stopped before it finished.") instead of staying **Syncing**.
- Docs: new `docs/deployment.md` (Hostinger / shared hosting guide).
- Tests: scheduled worker on/off, job failure handling. 114 tests.
- Files: `config/queue.php`, `routes/console.php`, `app/Jobs/SyncSource.php`, `.env.example`, `tests/Feature/SourceSyncTest.php`, `README.md`, `CLAUDE.md`, `docs/{deployment,README,getting-started,routes,CHANGELOG}.md`, `docs/features/sources.md`.

### 2026-10-07 — NeboTV name, logo and favicons
- The panel is now called **NeboTV**: sidebar, mobile top bar, sign-in page and page titles use `config('app.name')` (`APP_NAME=NeboTV`, also the default in `config/app.php`) instead of the translated "IPTV Panel" string, which was removed from `lang/mk.json`.
- New logo: `nebotv` icon (TV with a star, "nebo" = sky) in the brand mark. Favicons (`favicon.svg`, `favicon.ico`), `apple-touch-icon.png`, `icon-192.png`, `icon-512.png` and `site.webmanifest` so it can be added to a phone home screen; linked via the new `admin/partials/favicons` partial.
- Wordmark: `<x-brand-name />` shows "Nebo" + gradient "TV" in the sidebar, top bar and sign-in page (new CSS variables `--brand-from`, `--brand-to`, `--sidebar-brand-from`, `--sidebar-brand-to`). Full logo files `public/logo{,-dark}.{svg,png}` for use outside the panel.
- Changing `APP_NAME` changes the session cookie name, so admins are signed out once after updating `.env`.
- Tests: branding check in `AuthTest`. 111 tests.
- Files: `config/app.php`, `.env.example`, `resources/views/layouts/admin.blade.php`, `resources/views/auth/login.blade.php`, `resources/views/components/{icon,brand-name}.blade.php`, `public/css/admin.css`, `public/logo{,-dark}.{svg,png}`, `resources/views/admin/partials/favicons.blade.php`, `public/{favicon.svg,favicon.ico,apple-touch-icon.png,icon-192.png,icon-512.png,site.webmanifest}`, `lang/mk.json`, `tests/Feature/AuthTest.php`, `README.md`, `CLAUDE.md`, `docs/**`.

### 2026-10-07 — English and Macedonian translations
- Panel and sign-in page are translatable; English (default) and Macedonian ship. A language switcher (sidebar + sign-in page) posts to the new `POST /locale` route, which stores a `locale` cookie; `SetLocale` middleware (web group) applies it, falling back to `APP_LOCALE`. Supported languages are listed in `config('app.locales')`.
- Every view string, flash message and custom validation message now goes through `__()` / `trans_choice()` (English text as key). Dates with month names use `translatedFormat()`; relative dates follow the locale through Carbon.
- Macedonian strings in `lang/mk.json`; Laravel's validation/auth/pagination/password messages and form field names in `lang/mk/*.php`.
- Bulk-action flash messages and channel/user counts use proper plural forms instead of `str()->plural()`.
- Tests: `LocaleTest` (switching, cookie fallback, translated pages, flash and validation messages, plurals, every code string has a Macedonian entry with the same placeholders). 110 tests.
- Docs: new `docs/features/localization.md`; CLAUDE.md and the `update-docs`, `add-admin-resource`, `write-tests`, `run-app` skills now require translated strings.
- Files: `config/app.php`, `bootstrap/app.php`, `routes/web.php`, `app/Http/Middleware/SetLocale.php`, `app/Http/Controllers/LocaleController.php`, `app/Http/Controllers/{Admin/*,Auth/AuthController}.php`, `resources/views/**`, `public/css/admin.css`, `lang/**`, `tests/Feature/LocaleTest.php`, `docs/**`, `CLAUDE.md`, `.claude/skills/*`.

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
