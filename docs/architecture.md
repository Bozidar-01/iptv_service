# Architecture

## Stack

- Laravel 13, PHP 8.3+, MySQL (dev), sqlite in-memory (tests)
- Blade views + hand-written CSS (`public/css/admin.css`, loaded with `asset()`)
- Pest 4 for tests, Pint for style
- Sessions, cache and queue use the `database` driver

## Layers

```
routes/web.php
  └─ Controllers (app/Http/Controllers)      validate input, call a service, return view/redirect
       └─ Services (app/Services)             business logic, queries
            └─ Models (app/Models)            Eloquent, relationships, small helpers
```

| Service | Responsibility |
|---|---|
| `UserService` | List/create/update/delete users, hash passwords, sync bouquets, toggle status |
| `ChannelService` | Channel CRUD **and** category CRUD |
| `BouquetService` | Bouquet CRUD, sync channels |
| `TokenService` | Create/validate access tokens, build M3U text |
| `StreamService` | Authorise playback, upsert active session, write stream log, return target URL |

Services are resolved by the container via constructor injection in controllers.

## Conventions

- Inline `$request->validate()` in controllers; validated array passed straight to the service.
- Many-to-many assignments arrive as arrays (`channels[]`, `bouquets[]`) and are applied with `sync()` in the service.
- Success feedback: `redirect()->route('admin.x.index')->with('success', '…')`.
- Models declare `#[Fillable]` / `#[Hidden]` attributes and a `casts()` method.
- Admin views extend `layouts.admin` and use `@section('title')` / `@section('content')`.
- Pivot tables are named `bouquet_channel` and `user_bouquet` (note: not alphabetical for the latter — always pass the table name to `belongsToMany`).

## Request flows

### Admin
`/login` → `AuthController@login` (rejects non-admins) → session → `/admin/*` guarded by `auth` middleware.

### Subscriber playback
```
IPTV player ──GET /m3u?username&password──▶ M3uController
                                              ├─ verify credentials, status, expiry
                                              ├─ reuse or create AccessToken (30 days)
                                              └─ TokenService::getM3u → #EXTM3U with /play/{id}/{token}
IPTV player ──GET /play/{id}/{token}──────▶ PlayController
                                              ├─ TokenService::validateToken
                                              ├─ StreamService::isAllowed
                                              ├─ StreamService::getStreamUrl (log + session)
                                              └─ 302 → channel.stream_url
```

See [features/playlist-and-streaming.md](features/playlist-and-streaming.md).
