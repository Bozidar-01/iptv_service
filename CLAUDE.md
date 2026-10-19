# NeboTV (IPTV Service)

Laravel 13 (PHP 8.4) IPTV panel. Admins import channels from provider M3U playlists ("sources"), group them into bouquets and create users. Each user gets a playlist link for a TV IPTV app. Playback goes through `/play`, which checks access and connection limits, logs, and redirects to the provider stream.

## Documentation — always keep it current

Docs live in [`docs/`](docs/README.md). **Every change that adds, removes or alters behaviour must update the docs in the same task** — no exceptions, even for small fixes. Use the `update-docs` skill. At minimum:

- Add an entry to [`docs/CHANGELOG.md`](docs/CHANGELOG.md) under `Unreleased`.
- Update the relevant feature doc (`docs/features/*.md`), [`docs/data-model.md`](docs/data-model.md) for schema changes, [`docs/routes.md`](docs/routes.md) for route changes.
- If you fix something listed in [`docs/known-issues.md`](docs/known-issues.md), remove it there; if you notice a new problem you are not fixing, add it.

## Commands

Docker is the default way to run the app (see the `run-app` skill):

```bash
docker compose up -d --build                       # http://localhost:8000, admin / admin123 (or ADMIN_* in .env)
docker compose exec app php artisan test           # tests; all must pass before finishing a task
docker compose exec app php artisan <cmd>          # artisan against the Docker MySQL
docker compose logs -f queue                       # source imports
```

Without Docker: `composer dev` (server + queue + logs), `php artisan schedule:work`, `composer test`. Before finishing a task run `composer check` (Pint, PHPStan, tests; `vendor/bin/pint` fixes style) and, for JS/UI changes, `npm run e2e` (Playwright, needs Chrome; see [docs/getting-started.md](docs/getting-started.md#tests-and-style)). Shared hosting (Hostinger): one `schedule:run` cron + `QUEUE_SCHEDULED_WORKER=true`, see [docs/deployment.md](docs/deployment.md).

## Architecture

- **Controllers stay thin; logic lives in `app/Services/`**, injected via the constructor (`protected $xService;`). Long work goes in a queued job (`app/Jobs`) that calls a service.
- Services: `UserService`, `ChannelService` (channels, categories, bulk actions, `attachToBouquet`), `BouquetService`, `SourceService`, `SourceSyncService`, `M3uParser`, `TokenService` (tokens + M3U output), `StreamService` (access, limits, sessions, logs), `StreamProxyService` (optional play-through-server), `StreamCheckService` (stream check, auto-disable, alerts), `BackupService` (nightly DB dump), `ReceiverService` (signed links to the satellite receiver relay), `EpgImportService` + `EpgService` (TV guide), `SubscriptionReminderService`, `ChannelHealthService` (reliability, viewer trouble) + `ChannelFailoverService` (ranked backups), `ListCacheService` (ready lists for apps: call `ListCacheService::bump()` after raw channel updates that skip `updated_at`), `PlaylistOptionsService`, `ChannelNumberService`, `ChannelCatalogService` (iptv-org logos/guide ids), `CatchupService`, `SaleService` (prices, sales, reseller payments: call `record()` wherever months are added to a user).
- `relay/` is a separate Python 3 program that runs on the Enigma2 receiver (stdlib only). Its link signature must match `ReceiverService::sign()`; run `python3 relay/test_relay.py` after changing either.
- Inline `$request->validate([...])`. Feedback via `->with('success'|'error', __('...'))`, which the layout renders.
- Models use `#[Fillable]` / `#[Hidden]` attributes and a `casts()` method.
- Users have no email: auth is by `username`. `role` = `admin|user`, `status` = `active|disabled`. Only admins can log in.

## Scale and MySQL rules

- Provider playlists can have 10k–100k entries. Never loop per channel for writes. Use `upsert`, `insertUsing` (`ChannelService::attachToBouquet`) or query-based updates. Never render unbounded channel lists (paginate, or work per category).
- MySQL forbids `UPDATE/DELETE t ... WHERE id IN (SELECT ... FROM t)`: wrap the subquery in `DB::query()->fromSub(...)`.
- Check new migrations up **and** down on MySQL (drop FKs before the indexes they use).

## UI

- Admin views: `@extends('layouts.admin')`, `.page-header` → `.card` components from `public/css/admin.css`, icons via `<x-icon name="..."/>`, partials in `resources/views/admin/partials/`. Full class list: [docs/architecture.md#ui-conventions](docs/architecture.md#ui-conventions).
- Colours only via CSS variables. A new token needs its light value and the same dark value in **both** dark blocks of `admin.css` (OS dark + `[data-theme="dark"]`, see [docs/features/theme.md](docs/features/theme.md)). No Tailwind classes, no inline colours. Check light and dark mode (sun/moon buttons) and 375 px wide.
- Use plain, clear wording ("Add source", "Disable"). No jargon.

## Translations (English + Macedonian)

- **Every user-visible string goes through `__()` / `trans_choice()`** (views, `title`/`placeholder`/`aria-label`/`data-confirm`, flash and custom validation messages). The English text is the key.
- **Add the Macedonian text to `lang/mk.json` in the same task**; new form fields also go in `attributes` in `lang/mk/validation.php`. `LocaleTest` fails on any missing key or mismatched `:placeholder`.
- Placeholders, not concatenation; links/`<code>` inside sentences via a placeholder + `{!! !!}` with `e()`; `translatedFormat()` for dates. Details: [docs/features/localization.md](docs/features/localization.md).

## Gotchas

- **Tests must never touch a real DB.** `phpunit.xml` forces SQLite with `<env>` *and* `<server>` (Laravel reads `$_SERVER` first, and Docker sets `DB_*`). `Tests\TestCase::beforeRefreshingDatabase()` throws otherwise. Don't remove either.
- Imports need a queue worker; without one, sources stay "Queued". `php artisan iptv:sync-sources --now` runs inline.
- Play/playlist URLs use the request host. TVs need the LAN IP, not `localhost`.
- `Http::fake` with a static response reuses one body stream. Use `fn () => Http::response(...)` when a test downloads twice.
- Check `docs/known-issues.md` before debugging.

## Workflow: branches, `main` is production

**`main` deploys to the live server on every push** (checks pass → `scripts/deploy.sh`: backup, migrate, caches). So never commit or push to `main`. Every feature, fix, refactor or docs change goes on its own branch (`feature/…`, `fix/…`, `docs/…`, `chore/…`) created from `origin/main` (`start-work` skill), and reaches `main` only when it is 100% ready and the user says to ship it, through a pull request with green checks (`ship-to-main` skill). The deploy never edits the server's `.env`: say which new variables the user must add before merging. Details: [docs/deployment.md](docs/deployment.md#branches-and-releases).

## Skills

- `start-work` — begin every task on a new branch from `origin/main`, never on `main`.
- `ship-to-main` — readiness checklist, pull request, CI, user go-ahead, merge (= deploy), verify.
- `update-docs` — update docs/changelog after any change.
- `run-app` — start, inspect and debug the Docker stack; verify in the browser.
- `add-admin-resource` — scaffold a new admin CRUD (migration → model → service → controller → routes → views → tests → docs).
- `write-tests` — Pest conventions and must-cover scenarios.
