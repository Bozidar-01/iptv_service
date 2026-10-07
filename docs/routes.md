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
| GET | `/play/{channel_id}/{token}` | `play` | `PlayController@play` | 302 to stream |

## Admin (`auth` middleware, prefix `/admin`, name prefix `admin.`)

| Method | URI | Name | Handler |
|---|---|---|---|
| GET | `/admin/dashboard` | `admin.dashboard` | `DashboardController@index` |
| resource | `/admin/categories` | `admin.categories.*` | `CategoryController`: only index, store, update, destroy |
| resource | `/admin/channels` | `admin.channels.*` | `ChannelController`, all except `show` |
| resource | `/admin/bouquets` | `admin.bouquets.*` | `BouquetController`, all except `show` |
| resource | `/admin/users` | `admin.users.*` | `UserController`, all except `show` |
| POST | `/admin/users/{user}/toggle` | `admin.users.toggle` | `UserController@toggleStatus` |
| GET | `/admin/logs` | `admin.logs.index` | `LogController@index` |

Admin resource routes are limited with `->only()` / `->except()` so that only implemented controller methods are registered.
