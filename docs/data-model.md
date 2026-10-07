# Data Model

Migrations in `database/migrations/`. The custom ones are `2026_04_20_063000_{1..7}_*.php` and must run in that order (foreign keys). The default users migration was modified for this project.

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
| remember_token, timestamps | | |

Model helpers: `isAdmin()`, `isActive()` (status active **and** not expired), `isExpired()`.

### categories
`id`, `name`, timestamps. Has many channels.

### channels
| Column | Type | Notes |
|---|---|---|
| name | string | |
| stream_url | string | upstream URL the player is redirected to |
| logo_url | string, nullable | used as `tvg-logo` |
| category_id | FK → categories, nullable, `ON DELETE SET NULL` | |
| is_active | bool, default true | inactive channels are hidden from M3U and refused on play |

### bouquets
`id`, `name` (unique by validation), timestamps. A bouquet is a subscription pack.

### bouquet_channel (pivot)
`id`, `bouquet_id` FK cascade, `channel_id` FK cascade.

### user_bouquet (pivot)
`id`, `user_id` FK cascade, `bouquet_id` FK cascade.

### access_tokens
`id`, `user_id` FK cascade, `token` (unique, 32 random chars), `expires_at`, timestamps. Created on first `/m3u` call, valid 1 month.

### active_sessions
`id`, `user_id` FK, `channel_id` FK, `started_at`, `last_activity_at`. No Laravel timestamps. One row per user+channel (upserted on each play). Counted as "active" if `last_activity_at` is within `StreamService::SESSION_TIMEOUT_MINUTES` (5). Stale rows are pruned by `model:prune` (model is `Prunable`).

### stream_logs
`id`, `user_id` FK, `channel_id` FK, `ip`, `user_agent`, `started_at`. One row per successful play request.

### Framework tables
`sessions`, `password_reset_tokens`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`.

## Relationships

```
Category 1─* Channel *─* Bouquet *─* User
User 1─* AccessToken
User 1─* ActiveSession *─1 Channel
User 1─* StreamLog     *─1 Channel
```
