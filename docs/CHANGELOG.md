# Changelog

Format: newest first. Each entry: date, short description, files touched. Add new entries under **Unreleased** as part of every change.

## Unreleased

### 2026-10-08 — TV guide, backup streams, stable channel IDs, expiry reminders
- **TV guide (EPG):**
  - New **TV guide** page with XMLTV sources (`epg_sources`), stored as `epg_channels` / `epg_programmes`. Imported nightly by `iptv:sync-epg` (`SyncEpgSource`, streaming `XMLReader`, gz, `EPG_DAYS` window, filler titles skipped).
  - Channels are matched by tvg-id (also without `@SD`) or by normalised name (`EpgService`), or set by hand (**Guide ID** on the channel form, with *On now*).
  - Apps get the guide through the playlist header (`x-tvg-url`, `/epg/{token}.xml`), `xmltv.php`, and Xtream `get_short_epg` / `get_simple_data_table`. `tvg-id` / `epg_channel_id` is the guide id.
  - Default guides: iptv-epg.org files for MK, IT, RS, HR, BA and AL (`DefaultEpgSourcesSeeder`).
- **Backup streams:** a dead channel with a working twin (the admin's **Backup channel**, or the same tvg-id) stays on and plays the twin (`using_backup_id`, `Channel::playbackChannel()`) until it recovers. *Playing a backup* badge and filter.
- **Stable channel IDs:** imported channels are keyed by tvg-id when they have one, so renames and group moves keep the channel. Existing channels are moved to the new key on the next sync without changing their id.
- **Expiry reminders:** `iptv:expiry-reminders` (daily 08:00) creates `expiring` (within `EXPIRY_REMINDER_DAYS`) and `expired` alerts, once per user and expiry date. Shown in the panel and in a *Subscriptions* group in the email.
- Schema: `epg_*` tables; `channels.epg_source_id`, `epg_id`, `epg_manual`, `backup_channel_id`, `using_backup_id`; `stream_alerts.user_id`.
- Tests: `EpgTest`, `BackupChannelTest`, `ExpiryReminderTest`, stable-id tests in `SourceSyncTest`, `refakeHttp()` helper. 250 tests.
- Files: `app/Services/{EpgImportService,EpgService,SubscriptionReminderService,StreamCheckService,SourceSyncService,TokenService,XtreamService,StreamService,StreamPreviewService,ChannelService}.php`, `app/Jobs/SyncEpgSource.php`, `app/Console/Commands/{SyncEpgCommand,ExpiryRemindersCommand}.php`, `app/Models/{EpgSource,EpgChannel,EpgProgramme,Channel,StreamAlert}.php`, `app/Http/Controllers/{M3uController,XtreamController}.php`, `app/Http/Controllers/Admin/{EpgSourceController,ChannelController}.php`, `app/Mail/StreamAlertsMail.php`, `database/migrations/2026_10_08_*`, `database/seeders/{DefaultEpgSourcesSeeder,DatabaseSeeder}.php`, `config/iptv.php`, `routes/{web,player,console}.php`, `resources/views/admin/{epg/index,channels/_form,channels/index,partials/alert-item}.blade.php`, `resources/views/layouts/admin.blade.php`, `resources/views/emails/*`, `lang/mk.json`, `lang/mk/validation.php`, `.env.example`, `CLAUDE.md`, `docs/**`, `tests/**`.

### 2026-10-07 — Satellite receiver through the NeboTV relay
- New `relay/nebotv-relay.py`, a small Python 3 program that runs on the Enigma2 receiver (Octagon SF8008 / OpenATV). It streams a channel only for links signed by the panel, allows at most `max_streams` channels (tuners), adds the box's stream login, offers the chosen bouquets as M3U (markers become categories, encrypted channels left out with `fta_only`), reports `/status`, serves picons and keeps a DuckDNS name updated. With init script, example settings and `relay/test_relay.py`.
- New source type **Satellite receiver** (`sources.type = receiver`, `relay_secret` encrypted, `transcode`, `receiver_online`, `receiver_checked_at`). Imports the relay playlist. Never stream-checked.
- `ReceiverService`: `/play`, Xtream `/live` and the admin preview send receiver channels to a signed link that expires (`RECEIVER_LINK_MINUTES`, default 180). `iptv:check-receivers` runs every 5 minutes and sends `receiver_down` / `receiver_up` alerts.
- Import errors never store the relay secret.
- Docs: new `docs/features/receiver.md` (setup guide). Tests: `ReceiverTest` (14) and 9 relay tests. 223 tests.
- Files: `relay/*`, `app/Services/{ReceiverService,StreamService,StreamPreviewService,SourceSyncService,StreamCheckService}.php`, `app/Console/Commands/CheckReceiversCommand.php`, `app/Models/{Source,StreamAlert}.php`, `app/Mail/StreamAlertsMail.php`, `app/Http/Controllers/Admin/SourceController.php`, `database/migrations/2026_10_07_000009_add_receiver_columns_to_sources_table.php`, `config/iptv.php`, `routes/console.php`, `resources/views/admin/sources/{_form,index}.blade.php`, `resources/views/emails/*`, `lang/mk.json`, `lang/mk/validation.php`, `.env.example`, `CLAUDE.md`, `docs/**`, `tests/Feature/ReceiverTest.php`.

### 2026-10-07 — Admin-only middleware, admin from .env, per-source stream check, one check at a time
- Admin routes now also require `role = admin` (`EnsureAdmin`, alias `admin`). Other signed-in users are signed out.
- `AdminSeeder` reads `ADMIN_USERNAME` / `ADMIN_PASSWORD` from `.env`. Without a password: `admin123` locally, otherwise a random one printed once. It never changes an existing user. The hard-coded password is removed (it is still in git history: change it where it was used).
- Sources: **Check streams** switch (`sources.check_streams`, default on). Off = the stream check skips the source, and channels it had switched off come back. For channels that play only in some countries.
- Stream check: only one at a time (`stream-check:pending` cache counter). The button says when a check is already running, and the schedule doesn't pile up checks while the queue is stopped.
- Docs: replaced stale `admin / admin123` mentions. Tests: `AdminAccessTest`, more `StreamCheckTest`. 209 tests.
- Files: `app/Http/Middleware/EnsureAdmin.php`, `bootstrap/app.php`, `routes/web.php`, `database/seeders/AdminSeeder.php`, `database/migrations/2026_10_07_000008_add_check_streams_to_sources_table.php`, `app/Models/Source.php`, `app/Services/{SourceService,StreamCheckService}.php`, `app/Jobs/CheckStreams.php`, `app/Console/Commands/CheckStreamsCommand.php`, `app/Http/Controllers/Admin/{ChannelController,SourceController}.php`, `resources/views/admin/sources/_form.blade.php`, `lang/mk.json`, `.env.example`, `README.md`, `CLAUDE.md`, `docker-compose.yml`, `docker/php/entrypoint.sh`, `.claude/skills/run-app/SKILL.md`, `docs/**`, `tests/**`.

### 2026-10-07 — Reliability: login limits, confirmed failures, background task monitoring, stream headers
- **Login limits:** admin sign-in is locked for a minute after 5 wrong passwords (username + IP). App logins (`/m3u`, `/get.php`, `/player_api.php`, `/live`) are blocked for 10 minutes after 20 wrong attempts from one IP.
- **Stream check:** failed streams are retried once after 5 s before they count as offline, and the reason is stored (`channels.stream_error`: HTTP code, timeout, connect, web page, empty). It is shown in the badge tooltip, the dashboard and alerts/emails ("stopped working: No answer in time"). Full check: 3,619 online (was 3,578).
- **Background task monitoring** (`HealthService`, `iptv:heartbeat` every minute): `queue_stuck` and `jobs_failed` alerts (panel and email), a red dashboard banner when the scheduler or queue stops, and `GET /health` (200/503) for an uptime monitor.
- **Stream headers:** imports keep `http-user-agent` / `http-referrer` from `#EXTINF` attributes and `#EXTVLCOPT` lines (`channels.http_user_agent`, `http_referrer`; 299 channels). The M3U outputs them as `#EXTVLCOPT` lines and the stream check sends them (183 of those 299 now online).
- Tests: `LoginThrottleTest`, `HealthTest`, more `StreamCheckTest`, `StreamAlertTest`, `SourceSyncTest`, `M3uParserTest`. `Sleep` is faked in `Tests\TestCase`. 198 tests.
- Files: `app/Services/{StreamCheckService,HealthService,XtreamService,M3uParser,SourceSyncService,TokenService}.php`, `app/Http/Controllers/{HealthController,Auth/AuthController,Admin/DashboardController}.php`, `app/Console/Commands/HeartbeatCommand.php`, `app/Models/{Channel,StreamAlert}.php`, `app/Mail/StreamAlertsMail.php`, `database/migrations/2026_10_07_000006_add_stream_headers_and_errors.php`, `routes/{console,player}.php`, `resources/views/admin/{dashboard,channels/index}.blade.php`, `resources/views/emails/*`, `lang/mk.json`, `tests/**`, `docs/**`.

### 2026-10-07 — Connection limit counts devices; switching channels works
- Fix: a user with `max_connections` 1 who changed channel got `403 Access denied or connection limit reached` for 5 minutes (VLC showed "Your input can't be opened").
- The limit now counts **devices** (client IP + user agent): a device can switch channels freely, and its session moves to the new channel. Any other device needs a free slot, even in the same home and even on the same channel (the old "same channel doesn't use a slot" exception is gone).
- `active_sessions` is now one row per user + device. Migration `add_device_to_active_sessions_table` (nullable `ip`, `user_agent`).
- Tests: channel switching, second device in the same home, limit of 2. 201 tests.
- Files: `app/Services/StreamService.php`, `app/Models/ActiveSession.php`, `database/migrations/2026_10_07_000007_add_device_to_active_sessions_table.php`, `tests/Feature/{PlayTest,XtreamTest}.php`, `docs/**`.

### 2026-10-07 — Login with server, username and password (Xtream Codes API)
- TV apps can now log in with **server URL + username + password** as well as the M3U link: `player_api.php` (login info, live categories, live streams; VOD/series/EPG answer empty), `get.php` (M3U), `xmltv.php` (empty guide) and `/live/{username}/{password}/{id}.ts|m3u8` (same access checks, limits and logging as `/play`). New `XtreamService`, `XtreamController`.
- The user page has a new **Log in with username and password** card: server URL, username, password note, host and port, and setup steps. It replaces the old `/m3u?username=` hint.
- Player endpoints (`/m3u`, `/playlist`, `/play` and the Xtream ones) moved to `routes/player.php`, loaded without the `web` middleware group, so TV apps no longer create a session row on every request. `/m3u` with missing credentials now returns 401 instead of a validation redirect.
- Docker nginx: every `*.php` URL now runs `public/index.php`, so `/player_api.php` reaches Laravel. Restart the `web` container after updating.
- New `Channel` scopes `availableTo($user)` and `playlistOrder()`, shared by the M3U and the API.
- Tests: `XtreamTest`. 182 tests.
- Files: `app/Services/{XtreamService,TokenService}.php`, `app/Http/Controllers/{XtreamController,M3uController}.php`, `app/Http/Controllers/Admin/UserController.php`, `app/Models/Channel.php`, `routes/{player,web}.php`, `bootstrap/app.php`, `docker/nginx/default.conf`, `resources/views/admin/users/show.blade.php`, `public/css/admin.css`, `lang/mk.json`, `tests/Feature/XtreamTest.php`, `docs/**`.

### 2026-10-07 — Header alerts bell redesign
- The header bell is now a round, bordered button with a larger icon. The unread count sits on a ringed red badge, the bell wiggles on hover (off with reduced motion), and the button is highlighted while the menu is open. The menu fades in, has rounder corners, and the empty state shows an icon.
- Files: `resources/views/admin/partials/notifications.blade.php`, `public/css/admin.css` (`.notif-*`, local `--notif-ring` variable, no new colour tokens).

### 2026-10-07 — Alert email design
- Alert emails got a branded, email-safe layout: logo (inline CID image), headline with the problem count, alerts grouped by type with status pills, button to the alerts page, inbox preview text, and a plain-text version. The test email uses the same layout.
- Tests: email grouping/order and rendering. 171 tests.
- Files: `app/Mail/StreamAlertsMail.php`, `app/Console/Commands/SendAlertsCommand.php`, `resources/views/emails/stream-alerts{,-text}.blade.php`, `lang/mk.json`, `tests/Feature/StreamAlertTest.php`, `docs/features/alerts.md`.

### 2026-10-07 — Watch channels in the admin panel
- New **Watch** button on each row of the Channels page. It opens a player dialog so admins can see whether a channel is any good. HLS plays with hls.js and MPEG-TS with mpegts.js (jsDelivr, loaded on first use), trying the other if the first fails. When nothing plays, the dialog shows why. The stream address is shown with a copy button, for VLC.
- Streams go through the panel (`GET /admin/channels/{channel}/preview`, `StreamPreviewService`), so providers without CORS headers or on plain http still play. HLS playlists are rewritten so segments, keys and variants also go through the panel. Proxied URLs are HMAC-signed per channel with the app key, so the endpoint can't fetch other addresses. Live MPEG-TS stops after 30 minutes or when the player closes.
- Fix: the pagination partial used `__('Pagination')`, which on a case-insensitive filesystem (Docker on macOS) loads Laravel's `pagination.php` array and crashes any paginated page. It now uses `__('Page navigation')`.
- New CSS tokens `--player-bg` / `--player-text` (same in light and dark), `.modal-wide`, `.player`, `.player-status`. Macedonian strings added.
- Tests: `StreamPreviewTest` (auth, TS pass-through, playlist rewriting, signed segments, tampered signatures, provider errors, non-http URLs).
- Files: `app/Services/StreamPreviewService.php`, `app/Http/Controllers/Admin/ChannelController.php`, `routes/web.php`, `resources/views/admin/channels/index.blade.php`, `resources/views/admin/partials/pagination.blade.php`, `public/css/admin.css`, `lang/mk.json`, `tests/Feature/StreamPreviewTest.php`, `docs/{routes,architecture,known-issues,CHANGELOG}.md`, `docs/features/admin-panel.md`.

### 2026-10-07 — Stream check and alerts
- **Stream check** (`StreamCheckService`, `CheckStreams` job, `iptv:check-streams {source?} {--watched} {--now}`): concurrent GET of every enabled channel, keeping only the first 4 KB (`App\Support\CappedStream`). Dead channels are switched off with `auto_disabled`, and switched back on when they work again. Admin-disabled channels are left alone. Runs daily at 04:00, from the **Check streams** button on the Channels page, and every 15 minutes for watched channels. First run: 2,220 of 5,798 channels switched off.
- **Alerts:** a **Send me alerts** switch on channels (form + bulk actions) and sources. Watched channels that stop or start working, and watched sources whose import fails, create a `StreamAlert`. They show in a header bell with an unread count, on a new Alerts page and in a dashboard **Watched channels** card. `iptv:send-alerts` (every 5 minutes) emails them as one digest to `ALERT_MAIL_TO`. `.env.example` documents Brevo SMTP. `iptv:send-alerts --test` sends a test email.
- Channels list: *Not working* and *Alerts on* status filters, red *Not working* badge, bell icon on watched channels. Dashboard Channels stat links to the switched-off channels.
- Schema: `channels.stream_status`, `stream_checked_at`, `auto_disabled`, `alert_on_down`; `sources.alert_on_down`; new `stream_alerts` table.
- Tests: `StreamCheckTest`, `StreamAlertTest`. 169 tests.
- Files: `app/Services/{StreamCheckService,ChannelService,SourceSyncService}.php`, `app/Support/CappedStream.php`, `app/Jobs/CheckStreams.php`, `app/Console/Commands/{CheckStreamsCommand,SendAlertsCommand}.php`, `app/Mail/StreamAlertsMail.php`, `app/Models/{Channel,Source,StreamAlert}.php`, `app/Http/Controllers/Admin/{AlertController,ChannelController,SourceController,DashboardController}.php`, `app/Providers/AppServiceProvider.php`, `config/iptv.php`, `database/migrations/2026_10_07_00000{4,5}_*.php`, `routes/{web,console}.php`, `resources/views/admin/{alerts/index,partials/notifications,partials/alert-item,channels/index,channels/_form,sources/_form,dashboard}.blade.php`, `resources/views/layouts/admin.blade.php`, `resources/views/emails/stream-alerts.blade.php`, `resources/views/components/icon.blade.php` (`bell`), `public/css/admin.css`, `lang/mk.json`, `lang/mk/validation.php`, `.env.example`, `docs/**`, `CLAUDE.md`.

### 2026-10-07 — Default sources
- New `DefaultSourcesSeeder`: two hand-checked Macedonian playlists bundled in `database/seeders/playlists/` (bouquets **Macedonia TV** and **Macedonia Music**, categories `MK | National`, `MK | Regional`, `MK | Music`), plus auto-syncing public lists from Free-TV (bouquet **World**) and iptv-org (News, Sports, Movies, Kids, Music, Documentary). Imports are queued.
- `DatabaseSeeder` runs it only while there are no sources, so deleted defaults don't return on deploy. Running the seeder directly re-adds missing ones by name.
- Tests: `SeederTest` covers seeding, queued imports, idempotency and the Macedonian import. The admin test no longer assumes the `admin` username. 124 tests.
- Files: `database/seeders/{DatabaseSeeder,DefaultSourcesSeeder}.php`, `database/seeders/playlists/*.m3u`, `tests/Feature/SeederTest.php`, `docs/{getting-started,deployment,known-issues,CHANGELOG}.md`, `docs/features/sources.md`.

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
