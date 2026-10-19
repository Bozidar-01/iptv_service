# Routes

Defined in `routes/web.php`, plus `routes/player.php` for the endpoints TV apps call (loaded in `bootstrap/app.php` without the `web` middleware group: no session, cookies, CSRF or `SetLocale`). Health check at `/up` (from `bootstrap/app.php`). Every web route runs `SetLocale` (see [features/localization.md](features/localization.md)).

## Public

| Method | URI | Name | Handler | Notes |
|---|---|---|---|---|
| GET | `/` | – | closure | redirects to `login` |
| GET | `/login` | `login` | `AuthController@showLogin` | redirects admins to dashboard |
| POST | `/login` | – | `AuthController@login` | admins only; with two-factor on, continues at `/two-factor` |
| GET | `/two-factor` | `two-factor.show` | `AuthController@showTwoFactor` | code form; back to `/login` when no password was given or it took over 5 minutes |
| POST | `/two-factor` | `two-factor.verify` | `AuthController@twoFactor` | `code` = app code or recovery code; limited to 5 wrong tries |
| POST | `/logout` | `logout` | `AuthController@logout` | |
| POST | `/locale` | `locale.update` | `LocaleController@update` | body: `locale` (`en`/`mk`); sets the `locale` cookie, redirects back |
| POST | `/theme` | `theme.update` | `ThemeController@update` | body: `theme` (`light`/`dark`/`system`); sets or clears the unencrypted `theme` cookie, redirects back. Only used without JS |
## Player (`routes/player.php`)

`/play`, `/playlist/{token}.m3u` and `/epg/{token}.xml` use the `player` request limiter (429 when exceeded): `THROTTLE_PER_TOKEN` a minute per link + IP and `THROTTLE_PER_IP` per IP. `/stream` has its own (`THROTTLE_STREAM_PER_TOKEN`, 600). `/m3u`, `/get.php` and `/xmltv.php` use the same limiter; `/live` is limited per username + IP; `/player_api.php` per IP (`THROTTLE_API_PER_IP`, 600 a minute).

| Method | URI | Name | Handler | Notes |
|---|---|---|---|---|
| GET | `/health` | `health` | `HealthController` | `200`/`503` JSON for an uptime monitor |
| GET | `/m3u` | `m3u` | `M3uController@generate` | query: `username`, `password` |
| GET | `/playlist/{token}.m3u` | `playlist` | `M3uController@playlist` | per-user playlist link |
| GET | `/me/{token}` | `me` | `SelfServiceController@show`: the subscriber's own page; `?lang=en\|mk`; `?sent=yes\|again` after a renewal request; 404 for unknown / admin links |
| POST | `/me/{token}/favourite` | `me.favourite` | `SelfServiceController@favourite`: `do` add\|remove\|up\|down, `channel`; no session (422 when invalid) |
| POST | `/me/{token}/renew` | `me.renew` | `SelfServiceController@renew`: creates a `renewal_requested` alert (once per 24 h), redirects back to the page |
| GET | `/epg/{token}.xml` | `epg` | `M3uController@epg` | XMLTV guide for the playlist link |
| GET | `/play/{channel_id}/{token}` | `play` | `PlayController@play` | 302 to stream, or the stream itself for sources with *Hide provider address*; token = `{token}_{device key}` in playlists (or just the token), may end in `.m3u8`/`.ts`/… |
| GET | `/stream/{channel_id}/{token}?u=` | `stream` | `PlayController@stream` | one part (segment, key, variant) of a proxied stream; `u` = encrypted provider address; own limit `THROTTLE_STREAM_PER_TOKEN` |
| GET, POST | `/player_api.php` | `xtream.api` | `XtreamController@api` | Xtream Codes API: `username`, `password`, optional `action`, `category_id` |
| GET, POST | `/get.php` | `xtream.playlist` | `M3uController@generate` | Xtream Codes M3U |
| GET | `/xmltv.php` | `xtream.xmltv` | `XtreamController@xmltv` | XMLTV guide (username + password) |
| GET | `/live/{username}/{password}/{stream}` | `xtream.live` | `XtreamController@live` | `{stream}` = channel id + optional `.ts`/`.m3u8` |
| GET | `/catchup/{channel_id}/{token}?utc=&duration=` | `catchup` | `CatchupController@play` | catch-up from the M3U `catchup-source`: start (Unix), length (s); redirect to the provider's archive, 404 without one; `player` limiter |
| GET | `/timeshift/{username}/{password}/{duration}/{start}/{stream}` | `xtream.timeshift` | `CatchupController@timeshift` | Xtream catch-up: minutes, `Y-m-d:H-i`, channel id + `.ts`; `live` limiter |
| GET | `/streaming/timeshift.php` | `xtream.timeshift-php` | `CatchupController@timeshiftPhp` | the same with `username`, `password`, `stream`, `start`, `duration` |

## Admin (`auth` + `admin` middleware, prefix `/admin`, name prefix `admin.`)

| Method | URI | Name | Handler |
|---|---|---|---|
| GET | `/admin/dashboard` | `admin.dashboard` | `DashboardController@index` |
| POST | `/admin/sources/{source}/sync` | `admin.sources.sync` | `SourceController@sync` |
| resource | `/admin/sources` | `admin.sources.*` | `SourceController`, all except `show` |
| GET | `/admin/channels/{channel}/preview` | `admin.channels.preview` | `ChannelController@preview`: plays the channel's stream through the panel for the **Watch** player. Signed `u` (base64url upstream URL) + `s` query params for HLS segments/keys/variants; 403 on a bad signature, 502 when the provider fails, 422 for non-http URLs |
| POST | `/admin/channels/bulk` | `admin.channels.bulk` | `ChannelController@bulk` |
| GET | `/admin/cleanup` | `admin.cleanup.index` | `CleanupRuleController@index`: rules, ready-made sets, add form (admins only) |
| POST | `/admin/cleanup` | `admin.cleanup.store` | `store`: `kind`, `match`, `pattern`, `replacement`, `when_category`, `source_id`; `preview=1` shows the effect without saving |
| POST | `/admin/cleanup/country` | `admin.cleanup.country` | `CleanupRuleController@country`: `do` preview\|save\|apply, `split`, `min`, `exclude[]`, `enabled`; organize channels by country |
| POST | `/admin/cleanup/apply` | `admin.cleanup.apply` | applies the enabled rules to the stored channels |
| POST | `/admin/cleanup/pack/{pack}` | `admin.cleanup.pack` | adds a ready-made set (`tags`, `radio`, `satellite`, `genres`) |
| POST | `/admin/cleanup/{rule}/toggle` | `admin.cleanup.toggle` | enable / disable a rule |
| DELETE | `/admin/cleanup/{rule}` | `admin.cleanup.destroy` | |
| GET | `/admin/playlist-options` | `admin.playlist-options.index` | `PlaylistOptionsController@index`: names, numbers, logos and guide IDs, catch-up (admins only) |
| POST | `/admin/playlist-options` | `admin.playlist-options.update` | `update`: `quality_in_names`, `hide_worse_copies` |
| POST | `/admin/playlist-options/renumber` | `admin.playlist-options.renumber` | `renumber`: numbers every channel again in playlist order |
| POST | `/admin/playlist-options/fill-info` | `admin.playlist-options.fill-info` | `fillInfo`: queues `FillChannelInfo` (downloads the iptv-org database again) |
| GET | `/admin/channels/duplicates` | `admin.channels.duplicates` | `ChannelController@duplicates`: groups of copies with the suggested one (`?search=&page=`) |
| POST | `/admin/channels/duplicates` | `admin.channels.duplicates.apply` | `applyDuplicates`: `scope` page (`keep[group]=id`) or all, `search`; disables the other copies |
| POST | `/admin/channels/check-streams` | `admin.channels.check-streams` | `ChannelController@checkStreams` (optional `source_id`) |
| resource | `/admin/categories` | `admin.categories.*` | `CategoryController`: only index, store, update, destroy |
| resource | `/admin/channels` | `admin.channels.*` | `ChannelController`, all except `show` |
| resource | `/admin/bouquets` | `admin.bouquets.*` | `BouquetController`, all except `show` |
| resource | `/admin/users` | `admin.users.*` | `UserController` (all, `show` is the playlist page) |
| POST | `/admin/users/{user}/toggle` | `admin.users.toggle` | `UserController@toggleStatus` |
| GET | `/admin/users/export` | `admin.users.export` | `UserCsvController@export`: CSV of the list (`?search=&status=&reseller=&passwords=1`) |
| GET | `/admin/users/import` | `admin.users.import` | upload form |
| GET | `/admin/users/import/template` | `admin.users.import.template` | example CSV |
| POST | `/admin/users/import` | `admin.users.import.store` | `file` (CSV, 1 MB), `check_only`; shows created users, skipped rows and credits |
| POST | `/admin/users/bulk` | `admin.users.bulk` | `UserController@bulk`: `action` (enable, disable, extend, add_bouquet, remove_bouquet, delete), `scope` selected\|filtered, `ids[]`, `filters[]`, `months`, `bouquet_id` |
| POST | `/admin/users/{user}/renew` | `admin.users.renew` | `UserController@renew`: `months` 1\|3\|6\|12; admin free, reseller pays credits; subscribers with an end date only |
| POST | `/admin/users/{user}/playlist-token` | `admin.users.playlist-token` | `UserController@regeneratePlaylistToken` |
| POST | `/admin/users/{user}/sessions/{session}/disconnect` | `admin.users.sessions.disconnect` | `UserController@disconnectDevice`: ends the device's session, blocks it for 15 minutes; 404 for another user's session |
| POST | `/admin/users/{user}/sessions/disconnect` | `admin.users.sessions.disconnect-all` | `UserController@disconnectAllDevices` |
| GET | `/admin/epg` | `admin.epg.index` | `EpgSourceController@index` |
| POST | `/admin/epg` | `admin.epg.store` | `EpgSourceController@store` |
| POST | `/admin/epg/countries` | `admin.epg.countries` | `EpgSourceController@addCountries`: `all=1` or `countries[]` (two-letter codes); adds the free per-country guides and queues their import |
| POST | `/admin/epg/match` | `admin.epg.match` | `EpgSourceController@match` |
| POST | `/admin/epg/{epgSource}/sync` | `admin.epg.sync` | `EpgSourceController@sync` |
| DELETE | `/admin/epg/{epgSource}` | `admin.epg.destroy` | `EpgSourceController@destroy` |
| GET | `/admin/alerts` | `admin.alerts.index` | `AlertController@index` |
| POST | `/admin/alerts/read` | `admin.alerts.read` | `AlertController@markAllRead` |
| GET | `/admin/logs` | `admin.logs.index` | `LogController@index` |
| GET | `/admin/credits` | `admin.credits.index` | `CreditController@index`: history; a reseller sees own, an admin all or `?reseller=<id>` (staff group) |
| POST | `/admin/users/{user}/credits` | `admin.users.credits.store` | `CreditController@store`: `amount` (non-zero integer, negative takes back), `note`; admin only, reseller accounts only |
| POST | `/admin/users/{user}/credits/unlimited` | `admin.users.credits.unlimited` | `CreditController@unlimited`: remove the credit limit; admin only |
| GET | `/admin/users/trial` | `admin.users.trial` | `TrialController@create`: form (length, bouquets); staff |
| POST | `/admin/users/trial` | `admin.users.trial.store` | `store`: `hours` 24\|72\|168, `bouquets[]`; creates the trial, redirects to the user page |
| GET | `/admin/hide-channels` | `admin.hide-channels.index` | `HiddenChannelController@index`: channels of my bouquets (`?search=&category=&bouquet=&state=hidden\|visible`); staff |
| POST | `/admin/hide-channels` | `admin.hide-channels.bulk` | `bulk`: `action` hide\|show, `scope` selected\|filtered, `ids[]`, `filters[]`; edits the list for all of my users |
| GET | `/admin/users/{user}/channels` | `admin.users.channels` | `user`: the user's channels, same filters |
| POST | `/admin/users/{user}/channels` | `admin.users.channels.bulk` | `userBulk`: hide / show for this user alone |
| GET | `/admin/logs/refused` | `admin.logs.refused` | `LogController@refused`: refused plays with the reason (`?reason=&user=`); staff |
| GET | `/admin/sources/{source}/receiver` | `admin.receiver.show` | `ReceiverController@show`: relay status and the tidy form (receiver sources, admins) |
| POST | `/admin/sources/{source}/receiver/refresh` | `admin.receiver.refresh` | forget the cached status |
| POST | `/admin/sources/{source}/receiver/tidy` | `admin.receiver.tidy` | `criteria[]` radio\|internal\|no_guide; switches off matching channels |
| GET | `/admin/backups` | `admin.backups.index` | `BackupController@index`: backups per disk, last backup and check (admins only) |
| POST | `/admin/backups/verify` | `admin.backups.verify` | checks the newest backup on every disk |
| GET | `/admin/live` | `admin.live.index` | `LiveController@index`: devices watching now (staff; a reseller sees their own users) |
| GET | `/admin/statement` | `admin.statements.index` | `StatementController@index`: monthly statement of a reseller (`?reseller=&month=YYYY-MM`; a reseller always gets their own) |
| GET | `/admin/statement/csv` | `admin.statements.csv` | the same as CSV |
| GET | `/admin/sales` | `admin.sales.index` | `SalesReportController@index`: sales per reseller (`?days=30\|90\|365`); admins only |
| GET | `/admin/audit-log` | `admin.audit.index` | `AuditLogController@index`: who changed what (`?actor=&action=&search=`); admins only |
| GET | `/admin/launch-checklist` | `admin.launch-checklist.index` | `LaunchChecklistController@index`: what to have in place before launch (admins only) |
| POST | `/admin/launch-checklist/{item}` | `admin.launch-checklist.toggle` | ticks / unticks a manual item (others: 404) |
| GET | `/admin/statistics` | `admin.statistics.index` | `StatisticsController@index`: `?days=7\|30\|90` |
| GET | `/admin/security` | `admin.security.index` | `SecurityController@index`: two-factor status / setup and recent sign-ins |
| POST | `/admin/security/two-factor` | `admin.security.start` | `SecurityController@start`: new secret + QR code |
| POST | `/admin/security/two-factor/confirm` | `admin.security.confirm` | `code`; turns on and shows the recovery codes once |
| POST | `/admin/security/two-factor/recovery-codes` | `admin.security.recovery-codes` | `password`; new recovery codes |
| POST | `/admin/security/two-factor/disable` | `admin.security.disable` | `password` |

Routes under `/admin` are in two groups: the **staff** group (admins and resellers: `dashboard`, `users.*`, `alerts.*`, `logs.index`, `security.*`; resellers see only their own users) and the **admin** group (everything else; a reseller gets 403). See [features/admin-panel.md](features/admin-panel.md#resellers).

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
| `iptv:backup` | daily at 02:30: database dump to `BACKUP_DISK`, old ones removed after `BACKUP_KEEP_DAYS` |
| `model:prune --model=App\Models\ActiveSession --model=App\Models\StreamLog --model=App\Models\LoginEvent` | every 15 minutes (stale sessions, play history past `LOG_RETENTION_DAYS`, sign-in history past 180 days) |
| `queue:work --stop-when-empty --tries=1 --timeout=600 --max-time=240` | every minute, only when `QUEUE_SCHEDULED_WORKER=true` (shared hosting); `withoutOverlapping(15)`, in the background |
