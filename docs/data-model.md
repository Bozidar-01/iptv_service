# Data Model

Migrations in `database/migrations/`. The base schema is `2026_04_20_063000_{1..7}_*.php` (must run in that order: foreign keys). Later changes are `2026_10_07_00000{1..3}_*.php`. The default users migration was modified for this project. All migrations are tested on MySQL 8.4/9.7 (up and down) and SQLite.

## Tables

### users
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| username | string, unique | login identifier (no email column) |
| password | string | `hashed` cast |
| status | enum `active`,`disabled` | default `active` |
| role | enum `admin`,`user` | default `user` |
| expiration_date | datetime, nullable | null = never expires |
| max_connections | int | default 1 |
| playlist_token | string(64), unique, nullable | secret for `/playlist/{token}.m3u`; set on create (`User::booted`), backfilled by migration, hidden from serialization |
| remember_token, timestamps | | |

Model helpers: `isAdmin()`, `isActive()` (status active **and** not expired), `isExpired()`, `regeneratePlaylistToken()`. Relations: `bouquets`, `accessTokens`, `activeSessions`, `streamLogs`.

### categories
`id`, `name`, timestamps. Has many channels.

### sources
| Column | Type | Notes |
|---|---|---|
| name | string | |
| type | enum `url`,`file` | |
| url | string(2048), nullable | provider M3U URL (often contains provider credentials) |
| file_path | string, nullable | path on the default disk, `sources/{uuid}.m3u` |
| bouquet_id | FK → bouquets, nullable, `ON DELETE SET NULL` | imported channels are added here |
| skip_vod | bool, default true | |
| auto_sync | bool, default true | re-sync every 24 h |
| check_streams | bool, default true | include its channels in the stream check; turning it off re-enables channels the check switched off |
| alert_on_down | bool, default false | alerts for all its channels and for failed imports ([alerts.md](features/alerts.md)) |
| status | string | `pending`, `queued`, `syncing`, `success`, `failed` (`Source::STATUS_*`) |
| last_error | text, nullable | |
| channel_count | uint | after last successful sync |
| last_synced_at | timestamp, nullable | |

Helpers: `isBusy()` (queued/syncing), `isDueForSync()`.

### channels
| Column | Type | Notes |
|---|---|---|
| source_id | FK → sources, nullable, `ON DELETE CASCADE` | null = added manually |
| external_key | string(64), nullable | identity within the source (sha1 of group + name); unique with `source_id` |
| name | string | |
| stream_url | string(2048) | upstream URL the player is redirected to |
| logo_url | string(2048), nullable | used as `tvg-logo` |
| tvg_id | string, nullable | from the playlist; used as `tvg-id` in output (EPG matching) |
| http_user_agent | string(512), nullable | player identity the stream needs (`#EXTVLCOPT:http-user-agent`) |
| http_referrer | string(1024), nullable | referring page the stream needs (`#EXTVLCOPT:http-referrer`) |
| sort_order | uint, default 0 | position in the source playlist |
| category_id | FK → categories, nullable, `ON DELETE SET NULL` | |
| is_active | bool, default true | inactive channels are hidden from M3U and refused on play; never changed by re-syncs |
| stream_status | string(10), nullable | `online` / `offline` from the last stream check; null = never checked |
| stream_checked_at | timestamp, nullable | |
| stream_error | string(20), nullable | why the last check failed: `http:{code}`, `timeout`, `connect`, `html`, `empty` |
| auto_disabled | bool, default false | the stream check switched it off (and will switch it back on); cleared when an admin enables/disables it |
| alert_on_down | bool, default false | alert when it stops or starts working |

Scope `watched()` = `alert_on_down` or its source's `alert_on_down`. Helper `isWatched()`.

### stream_alerts
| Column | Type | Notes |
|---|---|---|
| type | string(20) | `down`, `up`, `sync_failed`, `queue_stuck`, `jobs_failed` (`StreamAlert::TYPE_*`) |
| channel_id | FK → channels, nullable, `ON DELETE SET NULL` | |
| source_id | FK → sources, nullable, `ON DELETE SET NULL` | |
| name | string | channel or source name when the alert was made |
| detail | string(50), nullable | `down`: the `stream_error`; `queue_stuck`: minutes waited; `jobs_failed`: count |
| read_at | timestamp, nullable, indexed | shared by all admins |
| mailed_at | timestamp, nullable, indexed | set by `iptv:send-alerts` |

Helpers: `unread()` scope, `message()` (translated text), `reason()`, `isProblem()`, `isSystem()`.

### bouquets
`id`, `name` (unique by validation), timestamps. A bouquet is a subscription pack.

### bouquet_channel (pivot)
`id`, `bouquet_id` FK cascade, `channel_id` FK cascade.

### user_bouquet (pivot)
`id`, `user_id` FK cascade, `bouquet_id` FK cascade.

### access_tokens
`id`, `user_id` FK cascade, `token` (unique, 32 random chars), `expires_at`, timestamps. Created on first `/m3u` call, valid 1 month.

### active_sessions
`id`, `user_id` FK, `channel_id` FK, `ip`, `user_agent` (nullable; together they identify the device), `started_at`, `last_activity_at`. No Laravel timestamps. One row per user + device, upserted on each play; `channel_id` is what that device watched last. Counted as "active" if `last_activity_at` is within `StreamService::SESSION_TIMEOUT_MINUTES` (5). Stale rows are pruned by `model:prune` (model is `Prunable`).

### stream_logs
`id`, `user_id` FK, `channel_id` FK, `ip`, `user_agent`, `started_at`. One row per successful play request.

### Framework tables
`sessions`, `password_reset_tokens`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`.

## Relationships

```
Source 1─* Channel          Source *─1 Bouquet (auto-add target)
Category 1─* Channel *─* Bouquet *─* User
User 1─* AccessToken
User 1─* ActiveSession *─1 Channel
User 1─* StreamLog     *─1 Channel
```
