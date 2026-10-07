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
   - **Identity:** `external_key = sha1(lower(group) | name)`, with `-2`, `-3`… for duplicates within one playlist. Unique per `(source_id, external_key)`.
   - **Category:** from `group-title` (or `#EXTGRP`), matched case-insensitively to existing categories, created if missing; empty → no category.
   - Upserted in chunks of 500. Updates `name`, `stream_url`, `logo_url`, `tvg_id`, `category_id`, `sort_order` (playlist position). **`is_active` is only set for new channels**, so channels you disabled stay disabled.
5. Deletes the source's channels that weren't in this playlist (`updated_at` older than the sync start).
6. If the source has a bouquet, adds all its channels that aren't in it yet.
7. Status → `success`, `channel_count`, `last_synced_at`. On any error: status `failed` and `last_error` (shown in the UI). An empty or invalid playlist fails *without* deleting existing channels.

Renaming a channel at the provider creates a new channel and deletes the old one, because the identity is group + name.

## Parser (`M3uParser`)

- Handles `#EXTM3U`, `#EXTINF` with any `key="value"` attributes (`tvg-id`, `tvg-name`, `tvg-logo`, `group-title`), commas inside quoted attributes, `#EXTGRP`, UTF-8 BOM, bare URL lines, and ignores other `#` lines (`#EXTVLCOPT`, `#KODIPROP`…).
- Name: text after the last comma outside attributes → `tvg-name` → URL filename.
- **VOD detection** (`isVod`): path contains `/movie/` or `/series/` (Xtream Codes style), or the extension is mp4/mkv/avi/mov/wmv/flv/m4v.

## Running syncs

- Queue worker required (`queue` container in Docker, or `php artisan queue:work`). The Sources page warns when a sync has been queued for more than 2 minutes, and auto-refreshes every 5 s while any sync is running.
- `php artisan iptv:sync-sources [source-id] [--due] [--now]`: queue all (or one) source; `--due` only the ones due for auto-refresh; `--now` runs inline without the queue.
- Scheduler: `iptv:sync-sources --due` hourly (`routes/console.php`).

## Deleting

Deleting a source deletes its uploaded file and (via FK cascade) all of its channels, their bouquet links, sessions and logs.
