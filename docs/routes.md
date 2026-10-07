# Routes

Defined in `routes/web.php`. Health check at `/up` (from `bootstrap/app.php`).

## Public

| Method | URI | Name | Handler | Notes |
|---|---|---|---|---|
| GET | `/` | – | closure | redirects to `login` |
| GET | `/login` | `login` | `AuthController@showLogin` | redirects admins to dashboard |
| POST | `/login` | – | `AuthController@login` | admins only |
| POST | `/logout` | `logout` | `AuthController@logout` | |
| GET | `/m3u` | `m3u` | `M3uController@generate` | query: `username`, `password` |
| GET | `/playlist/{token}.m3u` | `playlist` | `M3uController@playlist` | per-user playlist link |
| GET | `/play/{channel_id}/{token}` | `play` | `PlayController@play` | 302 to stream; token may end in `.m3u8`/`.ts`/… |

## Admin (`auth` middleware, prefix `/admin`, name prefix `admin.`)

| Method | URI | Name | Handler |
|---|---|---|---|
| GET | `/admin/dashboard` | `admin.dashboard` | `DashboardController@index` |
| POST | `/admin/sources/{source}/sync` | `admin.sources.sync` | `SourceController@sync` |
| resource | `/admin/sources` | `admin.sources.*` | `SourceController`, all except `show` |
| POST | `/admin/channels/bulk` | `admin.channels.bulk` | `ChannelController@bulk` |
| resource | `/admin/categories` | `admin.categories.*` | `CategoryController`: only index, store, update, destroy |
| resource | `/admin/channels` | `admin.channels.*` | `ChannelController`, all except `show` |
| resource | `/admin/bouquets` | `admin.bouquets.*` | `BouquetController`, all except `show` |
| resource | `/admin/users` | `admin.users.*` | `UserController` (all, `show` is the playlist page) |
| POST | `/admin/users/{user}/toggle` | `admin.users.toggle` | `UserController@toggleStatus` |
| POST | `/admin/users/{user}/playlist-token` | `admin.users.playlist-token` | `UserController@regeneratePlaylistToken` |
| GET | `/admin/logs` | `admin.logs.index` | `LogController@index` |

Admin resource routes are limited with `->only()` / `->except()` so that only implemented controller methods are registered.

## Console

| Command | Schedule |
|---|---|
| `iptv:sync-sources {source?} {--due} {--now}` | `--due` hourly |
| `model:prune --model=App\Models\ActiveSession` | every 15 minutes |
