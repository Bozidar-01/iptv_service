# Routes

Defined in `routes/web.php`, plus `routes/player.php` for the endpoints TV apps call (loaded in `bootstrap/app.php` without the `web` middleware group: no session, cookies, CSRF or `SetLocale`). Health check at `/up` (from `bootstrap/app.php`). Every web route runs `SetLocale` (see [features/localization.md](features/localization.md)).

## Public

| Method | URI | Name | Handler | Notes |
|---|---|---|---|---|
| GET | `/` | – | closure | redirects to `login` |
| GET | `/login` | `login` | `AuthController@showLogin` | redirects admins to dashboard |
| POST | `/login` | – | `AuthController@login` | admins only |
| POST | `/logout` | `logout` | `AuthController@logout` | |
| POST | `/locale` | `locale.update` | `LocaleController@update` | body: `locale` (`en`/`mk`); sets the `locale` cookie, redirects back |
| POST | `/theme` | `theme.update` | `ThemeController@update` | body: `theme` (`light`/`dark`/`system`); sets or clears the unencrypted `theme` cookie, redirects back. Only used without JS |
## Player (`routes/player.php`)

`/play`, `/playlist/{token}.m3u` and `/epg/{token}.xml` use the `player` request limiter (429 when exceeded): `THROTTLE_PER_TOKEN` a minute per link + IP and `THROTTLE_PER_IP` per IP.

| Method | URI | Name | Handler | Notes |
|---|---|---|---|---|
| GET | `/health` | `health` | `HealthController` | `200`/`503` JSON for an uptime monitor |
| GET | `/m3u` | `m3u` | `M3uController@generate` | query: `username`, `password` |
| GET | `/playlist/{token}.m3u` | `playlist` | `M3uController@playlist` | per-user playlist link |
| GET | `/epg/{token}.xml` | `epg` | `M3uController@epg` | XMLTV guide for the playlist link |
| GET | `/play/{channel_id}/{token}` | `play` | `PlayController@play` | 302 to stream; token may end in `.m3u8`/`.ts`/… |
| GET, POST | `/player_api.php` | `xtream.api` | `XtreamController@api` | Xtream Codes API: `username`, `password`, optional `action`, `category_id` |
| GET, POST | `/get.php` | `xtream.playlist` | `M3uController@generate` | Xtream Codes M3U |
| GET | `/xmltv.php` | `xtream.xmltv` | `XtreamController@xmltv` | XMLTV guide (username + password) |
| GET | `/live/{username}/{password}/{stream}` | `xtream.live` | `XtreamController@live` | `{stream}` = channel id + optional `.ts`/`.m3u8` |

## Admin (`auth` + `admin` middleware, prefix `/admin`, name prefix `admin.`)

| Method | URI | Name | Handler |
|---|---|---|---|
| GET | `/admin/dashboard` | `admin.dashboard` | `DashboardController@index` |
| POST | `/admin/sources/{source}/sync` | `admin.sources.sync` | `SourceController@sync` |
| resource | `/admin/sources` | `admin.sources.*` | `SourceController`, all except `show` |
| GET | `/admin/channels/{channel}/preview` | `admin.channels.preview` | `ChannelController@preview`: plays the channel's stream through the panel for the **Watch** player. Signed `u` (base64url upstream URL) + `s` query params for HLS segments/keys/variants; 403 on a bad signature, 502 when the provider fails, 422 for non-http URLs |
| POST | `/admin/channels/bulk` | `admin.channels.bulk` | `ChannelController@bulk` |
| POST | `/admin/channels/check-streams` | `admin.channels.check-streams` | `ChannelController@checkStreams` (optional `source_id`) |
| resource | `/admin/categories` | `admin.categories.*` | `CategoryController`: only index, store, update, destroy |
| resource | `/admin/channels` | `admin.channels.*` | `ChannelController`, all except `show` |
| resource | `/admin/bouquets` | `admin.bouquets.*` | `BouquetController`, all except `show` |
| resource | `/admin/users` | `admin.users.*` | `UserController` (all, `show` is the playlist page) |
| POST | `/admin/users/{user}/toggle` | `admin.users.toggle` | `UserController@toggleStatus` |
| POST | `/admin/users/{user}/playlist-token` | `admin.users.playlist-token` | `UserController@regeneratePlaylistToken` |
| GET | `/admin/epg` | `admin.epg.index` | `EpgSourceController@index` |
| POST | `/admin/epg` | `admin.epg.store` | `EpgSourceController@store` |
| POST | `/admin/epg/match` | `admin.epg.match` | `EpgSourceController@match` |
| POST | `/admin/epg/{epgSource}/sync` | `admin.epg.sync` | `EpgSourceController@sync` |
| DELETE | `/admin/epg/{epgSource}` | `admin.epg.destroy` | `EpgSourceController@destroy` |
| GET | `/admin/alerts` | `admin.alerts.index` | `AlertController@index` |
| POST | `/admin/alerts/read` | `admin.alerts.read` | `AlertController@markAllRead` |
| GET | `/admin/logs` | `admin.logs.index` | `LogController@index` |

Admin resource routes are limited with `->only()` / `->except()` so that only implemented controller methods are registered.

## Console

| Command | Schedule |
|---|---|
| `iptv:sync-sources {source?} {--due} {--now}` | `--due` hourly |
| `iptv:check-streams {source?} {--watched} {--now}` | all channels daily at 04:00, `--watched` every 15 minutes |
| `iptv:send-alerts {--test}` | every 5 minutes, without overlapping |
| `iptv:heartbeat` | every minute |
| `iptv:check-receivers` | every 5 minutes |
| `iptv:sync-epg {--now}` | daily at 03:30 |
| `iptv:expiry-reminders` | daily at 08:00 |
| `model:prune --model=App\Models\ActiveSession --model=App\Models\StreamLog` | every 15 minutes (stale sessions, play history past `LOG_RETENTION_DAYS`) |
| `queue:work --stop-when-empty --tries=1 --timeout=600 --max-time=240` | every minute, only when `QUEUE_SCHEDULED_WORKER=true` (shared hosting); `withoutOverlapping(15)`, in the background |
