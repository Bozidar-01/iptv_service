# Architecture

## Stack

- Laravel 13, PHP 8.4 (8.3+), MySQL 8.4 (Docker) / any MySQL locally, SQLite in-memory for tests
- Blade views + hand-written CSS (`public/css/admin.css`), no build step. Vite/Tailwind are installed but unused.
- Database-backed queue, cache and sessions
- Pest 4 for tests, Pint for style
- Docker: nginx + PHP-FPM + MySQL + queue worker + scheduler (`docker-compose.yml`, `Dockerfile`, `docker/`)

## Layers

```
routes/web.php, routes/console.php
  └─ Controllers (app/Http/Controllers)      validate input, call a service, return view/redirect
  └─ Commands (app/Console/Commands)         CLI entry points
       └─ Services (app/Services)             business logic, queries
       └─ Jobs (app/Jobs)                     queued work, delegates to services
            └─ Models (app/Models)            Eloquent, relationships, small helpers
```

| Service | Responsibility |
|---|---|
| `UserService` | List/filter users, create/update (hash password, sync bouquets), toggle status, count a user's channels |
| `ChannelService` | Channel CRUD, list filters (`filteredQuery`), bulk actions, `attachToBouquet()` (set-based insert), category CRUD and counts |
| `BouquetService` | Bouquet CRUD with counts, add whole categories |
| `SourceService` | Source CRUD, file storage, resolve "new" bouquet, queue syncs |
| `SourceSyncService` | Download/read a playlist, upsert channels, prune vanished ones, attach to bouquet, record status |
| `M3uParser` | Streaming M3U parser and VOD detection |
| `TokenService` | Access tokens, playback token reuse, M3U output |
| `StreamService` | Authorise playback, connection limit, session upsert, stream log |

| Job / command | |
|---|---|
| `SyncSource` | Queued sync of one source (unique per source, 600 s timeout, no retries) |
| `iptv:sync-sources` | Queue or run syncs (`--due`, `--now`) |

## Conventions

- Inline `$request->validate()` in controllers; the validated array goes to the service.
- Controller → service via constructor injection (`protected $xService;`).
- Many-to-many assignments arrive as arrays (`channels[]`, `bouquets[]`, `categories[]`). Bulk inserts into `bouquet_channel` use `ChannelService::attachToBouquet()` (one `INSERT … SELECT`), never per-row loops, because provider lists can have tens of thousands of channels.
- MySQL can't `UPDATE`/`DELETE` a table using a subquery on the same table (error 1093). Wrap such subqueries in a derived table (`DB::query()->fromSub(...)`), as `ChannelService::bulkAction` does.
- Success/error feedback: `redirect()->...->with('success'|'error', '…')`. The layout renders it.
- Models declare `#[Fillable]` / `#[Hidden]` attributes and a `casts()` method.
- Pivot tables: `bouquet_channel`, `user_bouquet` (always pass the table name to `belongsToMany`).

## UI conventions

- Pages `@extends('layouts.admin')`, set `@section('title')`, and optionally `@section('container', 'container-narrow')` for forms. Page-specific JS goes in `@push('scripts')`.
- Structure: `.page-header` (h1, p, `.page-actions`) → `.card` (`.card-header`, `.card-body`, `.card-footer`, `.toolbar` for filters, `.table-wrap` > `.table`) → `.empty` for empty states → `{{ $paginator->links('admin.partials.pagination') }}`.
- Components: `.btn` + `.btn-primary|secondary|ghost|danger|danger-ghost`, `.btn-sm`, `.btn-icon`; `.badge` + `-success|warning|danger|info|primary`, `.badge-dot`; `.alert-*`; form `.form-grid` > `.field` > `.label` / `.input|.select` / `.help`; `.check`, `.check-list` > `.check-tile`, `.segmented`; `.stats` > `.stat`; `.list`, `.dl`, `.copy-field`; `<dialog class="modal">`.
- Icons: `<x-icon name="tv" />` (`resources/views/components/icon.blade.php`). Add new icons there.
- Partials: `admin.partials.flash` (in the layout), `errors`, `pagination`, `source-status`, `user-status`.
- Colours only through CSS variables (`--primary`, `--surface`, `--text-muted`…). Each has light and dark values. No inline colours, no Tailwind classes.
- Breakpoints: 1100 px (grids collapse), 860 px (sidebar becomes a drawer, `.hide-sm` columns hide), 520 px.

## Request flows

### Admin
`/login` → `AuthController@login` (rejects non-admins) → session → `/admin/*` guarded by `auth`.

### Import
```
Admin "Add source" ─▶ SourceService::createSource ─▶ status=queued, SyncSource::dispatch
queue worker ─▶ SourceSyncService::sync ─▶ download → M3uParser → upsert channels (500/chunk)
                                         → delete vanished → attach to bouquet → status=success
scheduler (hourly) ─▶ iptv:sync-sources --due
```

### Subscriber playback
```
TV app ──GET /playlist/{token}.m3u──▶ M3uController@playlist ─┐
TV app ──GET /m3u?username&password─▶ M3uController@generate ─┴▶ status/expiry checks
                                         ├─ TokenService::playbackToken (reuse if > 7 days left)
                                         └─ TokenService::getM3u → #EXTM3U with /play/{id}/{token}.ext
TV app ──GET /play/{id}/{token}.ext─▶ PlayController
                                         ├─ validateToken → isAllowed → log + session
                                         └─ 302 → channel.stream_url
```

## Docker

| File | |
|---|---|
| `Dockerfile` | `php:8.4-fpm-alpine` + pdo_mysql, intl, zip, pcntl, bcmath, opcache; composer; code baked in |
| `docker/php/entrypoint.sh` | composer install if needed, `.env`/key, wait for DB, migrate + seed when `RUN_MIGRATIONS=true` (app container only) |
| `docker/php/php.ini` | 60 MB uploads, 512 MB memory, opcache revalidating on every request (dev) |
| `docker/nginx/default.conf` | front controller, 64 MB bodies, 300 s FastCGI timeout |
| `docker-compose.yml` | `app`, `queue`, `scheduler`, `web` (:8000), `db` (MySQL 8.4, :3307 on localhost) |
