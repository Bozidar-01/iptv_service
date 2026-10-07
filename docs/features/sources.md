# Sources (M3U import)

**Files:** `app/Models/Source.php`, `app/Services/SourceService.php`, `app/Services/SourceSyncService.php`, `app/Services/M3uParser.php`, `app/Jobs/SyncSource.php`, `app/Console/Commands/SyncSources.php`, `app/Http/Controllers/Admin/SourceController.php`, `resources/views/admin/sources/*`

A source is a provider playlist (by URL or uploaded file). Syncing it imports the playlist's entries as channels.

## Adding a source (admin → Sources → Add source)

| Field | Notes |
|---|---|
| Name | Label in the panel; also the name of the auto-created bouquet |
| Playlist | **From URL** (`url`, max 2048 chars) or **Upload file** (`.m3u`/`.m3u8`/`.txt`, max 50 MB; stored at `storage/app/private/sources/{uuid}.m3u`) |
| Add channels to bouquet | `new` (default on create) creates/reuses a bouquet named after the source; an existing bouquet; or none |
| Live TV only (`skip_vod`, default on) | Skips VOD entries (see below) |
| Refresh automatically (`auto_sync`, default on) | Re-sync every 24 h (`Source::AUTO_SYNC_HOURS`) |

Saving a new source queues a sync immediately. Editing doesn't; use **Sync** to apply changes.

## Sync

`SourceService::queueSync()` sets status `queued` and dispatches `SyncSource` (unique per source, 1 try, 600 s timeout). A second sync while one is queued or running is refused.

`SourceSyncService::sync()`:

1. Status → `syncing`.
2. Gets the playlist: downloads URL sources to a temp file (user agent `VLC/3.0.20 LibVLC/3.0.20`, 300 s timeout; non-2xx → fails), or reads the stored file.
3. Streams entries through `M3uParser` (constant memory, works for 100k-line lists).
4. For each entry (VOD skipped if `skip_vod`):
   - **Identity:** `external_key = 'tvg:' + sha1(lower(tvg-id))` when the entry has a `tvg-id`, else `sha1(lower(group) | name)`. Duplicates within one playlist get `-2`, `-3`…. Unique per `(source_id, external_key)`. Channels stored under the old group + name key are moved to their tvg-id key on the next sync (one `CASE` update per chunk), so they keep their id, bouquets and the admin's enabled/disabled choice.
   - **Category:** from `group-title` (or `#EXTGRP`), matched case-insensitively to existing categories, created if missing; empty → no category.
   - Upserted in chunks of 500. Updates `name`, `stream_url`, `logo_url`, `tvg_id`, `http_user_agent`, `http_referrer`, `category_id`, `sort_order` (playlist position). **`is_active` is only set for new channels**, so channels you disabled stay disabled.
5. Deletes the source's channels that weren't in this playlist (`updated_at` older than the sync start).
6. If the source has a bouquet, adds all its channels that aren't in it yet.
7. Matches the source's channels to the TV guide ([epg.md](epg.md)).
8. Status → `success`, `channel_count`, `last_synced_at`. On any error: status `failed` and `last_error` (shown in the UI). An empty or invalid playlist fails *without* deleting existing channels.

With a `tvg-id`, renaming a channel or moving it to another group keeps the same channel. Without one, a rename creates a new channel and deletes the old one. A provider that drops a channel's `tvg-id` also creates a new channel.

## Parser (`M3uParser`)

- Handles `#EXTM3U`, `#EXTINF` with any `key="value"` attributes (`tvg-id`, `tvg-name`, `tvg-logo`, `group-title`), commas inside quoted attributes, `#EXTGRP`, UTF-8 BOM, bare URL lines, and ignores other `#` lines (`#EXTVLCOPT`, `#KODIPROP`…).
- Name: text after the last comma outside attributes → `tvg-name` → URL filename.
- **Player identity / referring page:** `http-user-agent` and `http-referrer` (or `http-referer`), from `#EXTINF` attributes or `#EXTVLCOPT:` lines, are stored as `channels.http_user_agent` / `http_referrer`. Some streams refuse requests without them. Other `#EXTVLCOPT` options are ignored.
- **VOD detection** (`isVod`): path contains `/movie/` or `/series/` (Xtream Codes style), or the extension is mp4/mkv/avi/mov/wmv/flv/m4v.

## Running syncs

- Queue worker required (`queue` container in Docker, or `php artisan queue:work`). On shared hosting, `QUEUE_SCHEDULED_WORKER=true` runs `queue:work --stop-when-empty` from the scheduler every minute instead ([deployment.md](../deployment.md)). The Sources page warns when a sync has been queued for more than 2 minutes, and auto-refreshes every 5 s while any sync is running.
- `php artisan iptv:sync-sources [source-id] [--due] [--now]`: queue all (or one) source; `--due` only the ones due for auto-refresh; `--now` runs inline without the queue.
- Scheduler: `iptv:sync-sources --due` hourly (`routes/console.php`).
- If the job dies without `SourceSyncService` recording an error (worker killed by a host limit, timeout), `SyncSource::failed()` marks the source **Failed** with "The import was stopped before it finished." once the job comes back after `DB_QUEUE_RETRY_AFTER`. Channels already written are kept.

## Default sources (`DefaultSourcesSeeder`)

A fresh install gets nine sources, each with its own bouquet, so there is something to watch right away. `DatabaseSeeder` calls the seeder only while the `sources` table is empty, so sources an admin deletes don't come back on the next deploy. `php artisan db:seed --class=DefaultSourcesSeeder --force` re-adds any default whose **name** is missing. Every source is created through `SourceService::createSource()`, so its import is queued like a manual one.

| Source | Type | Bouquet | Categories |
|---|---|---|---|
| Macedonia – TV | file (`database/seeders/playlists/macedonia-tv.m3u`), 8 channels | Macedonia TV | `MK \| National`, `MK \| Regional` |
| Macedonia – Music | file (`database/seeders/playlists/macedonia-music.m3u`), 6 channels | Macedonia Music | `MK \| Music` |
| Free-TV (world, by country) | URL, `github.com/Free-TV/IPTV` | World | one per country |
| iptv-org – News / Sports / Movies / Kids / Music / Documentary | URL, `iptv-org.github.io/iptv/categories/*.m3u` | News / Sports / Movies / Kids / Music / Documentary | the list's `group-title` (e.g. `News`, `General;News`) |

- The Macedonian files are copied to `storage/app/private/sources/default-*.m3u`. They list only streams that worked from North Macedonia on 2026-10-07. MRT, M-Net, Alsat, MTM and TV ERA were left out: their public streams are offline, geo-blocked or use links that expire. Edit the `.m3u` files to add or fix channels, then **Sync** the source (or re-upload the file).
- The URL sources have `auto_sync` on and refresh daily. They hold about 5,800 channels together. On 2026-10-07 the stream check found 2,220 of them not working, and switched those off ([alerts.md](alerts.md)).
- All sources use `skip_vod`.

## Deleting

Deleting a source deletes its uploaded file and (via FK cascade) all of its channels, their bouquet links, sessions and logs.
