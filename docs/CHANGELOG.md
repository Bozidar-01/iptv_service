# Changelog

Format: newest first. Each entry: date, short description, files touched. Add new entries under **Unreleased** as part of every change.

## Unreleased

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
