# Data Model

Migrations in `database/migrations/`. The base schema is `2026_04_20_063000_{1..7}_*.php` (must run in that order: foreign keys). Later changes are `2026_10_07_00000{1..3}_*.php`. The default users migration was modified for this project. All migrations are tested on MySQL 8.4/9.7 (up and down) and SQLite.

## Tables

### users
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| username | string, unique | login identifier (no email column) |
| password | string | `hashed` cast |
| password_plain | text, nullable | encrypted copy shown on the edit page; null for users saved before it existed |
| status | enum `active`,`disabled` | default `active` |
| role | string(20): `admin`, `reseller`, `user` | default `user`; was an enum, now a string so roles can be added |
| owner_id | FK → users, nullable, `ON DELETE SET NULL` | the reseller a subscriber belongs to (`User::owner()`, `customers()`) |
| credits | int, nullable | reseller's credit balance (1 credit = 1 month of subscription for one user); null = not limited by credits (`CreditService`, `User::usesCredits()`) |
| max_customers | unsigned int, nullable | reseller limit: how many users they can create (null = no limit) |
| max_customer_connections | unsigned smallint, nullable | reseller limit: the most simultaneous streams they can give a user (null = no limit) |
| expiration_date | datetime, nullable | null = never expires |
| max_connections | int | default 1 |
| playlist_token | string(64), unique, nullable | secret for `/playlist/{token}.m3u`; set on create (`User::booted`), backfilled by migration, hidden from serialization |
| email | string, nullable | optional, not unique: where the subscriber gets expiry reminders (`SubscriptionReminderService`) |
| locale | string(5), nullable | `en` / `mk`: language of those emails (empty = the panel default) |
| two_factor_secret | text, nullable | authenticator secret, encrypted with `APP_KEY` (`TwoFactorService`); hidden |
| two_factor_recovery_codes | text, nullable | JSON list of HMAC hashes of unused recovery codes; hidden |
| two_factor_confirmed_at | timestamp, nullable | set when the first code was confirmed; null = two-factor off |
| two_factor_last_step | bigint, nullable | last accepted 30 s time step (a code works once) |
| remember_token, timestamps | | |

Model helpers: `isAdmin()`, `isActive()` (status active **and** not expired), `isExpired()`, `regeneratePlaylistToken()`. Relations: `bouquets`, `accessTokens`, `activeSessions`, `streamLogs`.

### login_events
`id`, `user_id` FK nullable (`SET NULL`), `username` (what was typed), `event` (`success`, `failed`, `denied`, `two_factor_failed`, `two_factor_enabled`, `two_factor_disabled`), `ip`, `user_agent`, `created_at` (indexed). Written by `AuthController` / `SecurityController`; pruned after 180 days (`MassPrunable`, one DELETE per chunk).

### user_hidden_channels
`user_id` FK → users, `channel_id` FK → channels (both `ON DELETE CASCADE`), primary key (`user_id`, `channel_id`). A channel hidden for one user; the bouquet is unchanged. See [features/hide-channels.md](features/hide-channels.md).

### owner_hidden_channels
`id`, `owner_id` FK → users nullable (`ON DELETE CASCADE`; null = the admins' own users, those without a reseller), `channel_id` FK → channels (`CASCADE`), unique (`owner_id`, `channel_id`; MySQL allows several null owners, so `ChannelHideService` skips rows that exist). A channel hidden for all users of that owner.

### checklist_ticks
`id`, `item` (key of a manual launch-checklist item, unique), `user_id` FK → users nullable (who ticked it, `SET NULL`), timestamps. Written by `LaunchChecklistService::toggle`; a row means the item is ticked off.

### credit_transactions
`id`, `user_id` FK → users (the reseller, `ON DELETE CASCADE`), `actor_id` FK → users nullable (who did it: an admin, or the reseller spending; `SET NULL`), `customer_id` FK → users nullable (the user it was spent on; `SET NULL`), `amount` (+ given, − spent or taken; 0 for *unlimited*), `balance_after` (null when the account is unlimited), `reason` (`admin_grant`, `admin_take`, `admin_unlimited`, `new_subscription`, `extended_subscription`), `note`, `created_at` (indexed). Only ever added to.

### categories
`id`, `name`, timestamps. Has many channels.

### sources
| Column | Type | Notes |
|---|---|---|
| name | string | |
| type | string(20): `url`, `file`, `receiver` | `receiver` = satellite receiver behind the NeboTV relay ([receiver.md](features/receiver.md)) |
| url | text, nullable, **encrypted** (`encrypted` cast) | provider M3U URL (often contains provider credentials); stored encrypted with `APP_KEY`, so keep `APP_KEY` safe and backed up |
| file_path | string, nullable | path on the default disk, `sources/{uuid}.m3u` |
| relay_secret | text, nullable, `encrypted` cast, hidden | receiver: shared secret for signed links and the relay's playlist/status |
| transcode | bool, default false | receiver: play the 720p transcoded stream (relay port 8002) |
| receiver_online | bool, nullable | receiver: last `/status` result; null = not checked yet |
| receiver_checked_at | timestamp, nullable | |
| bouquet_id | FK → bouquets, nullable, `ON DELETE SET NULL` | imported channels are added here |
| skip_vod | bool, default true | |
| auto_sync | bool, default true | re-sync every 24 h |
| proxy_streams | bool, default false | play this source's channels through the server (`StreamProxyService`); off for receivers |
| check_streams | bool, default true | include its channels in the stream check; turning it off re-enables channels the check switched off |
| alert_on_down | bool, default false | alerts for all its channels and for failed imports ([alerts.md](features/alerts.md)) |
| status | string | `pending`, `queued`, `syncing`, `success`, `failed` (`Source::STATUS_*`) |
| last_error | text, nullable | |
| channel_count | uint | after last successful sync |
| last_synced_at | timestamp, nullable | |

Helpers: `isBusy()` (queued/syncing), `isDueForSync()`, `isReceiver()`, `playlistUrl()` (the URL, or the relay playlist with `?key=`).

### channels
| Column | Type | Notes |
|---|---|---|
| source_id | FK → sources, nullable, `ON DELETE CASCADE` | null = added manually |
| external_key | string(64), nullable | identity within the source: `tvg:` + sha1(tvg-id), else sha1(group + name); unique with `source_id` |
| name | string | |
| stream_url | string(2048) | upstream URL the player is redirected to |
| logo_url | string(2048), nullable | used as `tvg-logo` |
| tvg_id | string, nullable | from the playlist; used as `tvg-id` in output (EPG matching) |
| epg_source_id | FK → epg_sources, nullable, `ON DELETE SET NULL` | guide this channel is matched to ([epg.md](features/epg.md)) |
| epg_id | string, nullable | the guide's channel id |
| epg_manual | bool, default false | `epg_id` was set by the admin; matching leaves it |
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
| backup_channel_id | FK → channels, nullable, `ON DELETE SET NULL` | admin's chosen backup |
| using_backup_id | FK → channels, nullable, `ON DELETE SET NULL` | backup playing now (set by the stream check) |

Scope `watched()` = `alert_on_down` or its source's `alert_on_down`. Helper `isWatched()`.

### stream_alerts
| Column | Type | Notes |
|---|---|---|
| type | string(20) | `down`, `up`, `sync_failed`, `queue_stuck`, `jobs_failed`, `receiver_down`, `receiver_up`, `expiring`, `expired`, `renewal_requested`, `backup_failed`, `backup_stale` (`StreamAlert::TYPE_*`) |
| channel_id | FK → channels, nullable, `ON DELETE SET NULL` | |
| source_id | FK → sources, nullable, `ON DELETE SET NULL` | |
| user_id | FK → users, nullable, `ON DELETE SET NULL` | subscription reminders |
| name | string | channel or source name when the alert was made |
| detail | string(50), nullable | `down`: the `stream_error`; `queue_stuck`: minutes waited; `jobs_failed`: count |
| read_at | timestamp, nullable, indexed | shared by all admins |
| mailed_at | timestamp, nullable, indexed | set by `iptv:send-alerts` |
| user_mailed_at | timestamp, nullable | when the subscriber was emailed about this expiry reminder (null = not yet, or no address); a failed send is retried on the next run |

Helpers: `unread()` scope, `message()` (translated text), `reason()`, `isProblem()`, `isSystem()`.

### epg_sources
`name`, `url`, `status` (as sources), `last_error`, `channel_count`, `programme_count`, `last_synced_at`, timestamps.

### epg_channels
`epg_source_id` FK cascade, `xmltv_id` (unique per source), `name`, `name_key` (indexed, `EpgService::nameKey`). No timestamps.

### epg_programmes
`epg_source_id` FK cascade, `xmltv_id`, `start`, `stop` (UTC), `title` (500), `description` (nullable). Index `(epg_source_id, xmltv_id, start)`. No timestamps.

### bouquets
`id`, `name` (unique by validation), timestamps. A bouquet is a subscription pack.

### bouquet_channel (pivot)
`id`, `bouquet_id` FK cascade, `channel_id` FK cascade.

### user_bouquet (pivot)
`id`, `user_id` FK cascade, `bouquet_id` FK cascade.

### access_tokens
`id`, `user_id` FK cascade, `token` (unique, 32 random chars), `expires_at`, timestamps. Created on first `/m3u` call, valid 1 month.

### active_sessions
`id`, `user_id` FK, `channel_id` FK, `ip`, `user_agent` (nullable; together they identify the device), `started_at`, `last_activity_at`. No Laravel timestamps. One row per user + device, upserted on each play; `channel_id` is what that device watched last. Counted as "active" if `last_activity_at` is within `StreamService::SESSION_TIMEOUT_MINUTES` (5). Stale rows are pruned by `model:prune` (model is `MassPrunable`). Index on `(user_id, last_activity_at)` for the connection-limit check.

### stream_logs
`id`, `user_id` FK, `channel_id` FK, `ip`, `user_agent`, `started_at`. One row per successful play request. Index on `started_at`. Rows older than `LOG_RETENTION_DAYS` (90) are deleted by `model:prune` (`MassPrunable`, one DELETE per chunk).

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
