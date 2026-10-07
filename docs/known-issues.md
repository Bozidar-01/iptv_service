# Known Issues

Remove entries when fixed (and note the fix in the CHANGELOG); add new ones as they're discovered.

## Bugs

_None currently known._

## Tooling

- **Pint is not runnable locally:** `vendor/bin/pint` fails because `vendor/laravel/pint/builds/pint` is missing. Run `composer install` to restore it.

## Design gaps / risks

- **Upstream URLs are exposed:** `/play` redirects (302) to the provider URL, which often contains the provider username/password. Anyone with a playlist can see it. Fixing this needs a stream proxy.
- **Provider credentials are stored in plain text** in `sources.url` and `channels.stream_url`.
- **Credentials in query string** for `/m3u?username=&password=` (standard for IPTV, but they end up in server/proxy logs). Prefer the `/playlist/{token}.m3u` link.
- **No rate limiting** on `/login`, `/m3u`, `/playlist`, `/play`.
- **Admin group only checks `auth`**, with no role middleware. Safe today only because non-admins are logged out at login.
- **No heartbeat:** a session only stays "active" for 5 minutes after the last `/play` request, so long viewing frees the slot.
- **Channel identity is group + name:** if a provider renames a channel or moves it to another group, it is re-created (new ID, bouquet membership only restored via the source's bouquet).
- **Bulk-deleted imported channels come back** on the next sync. Disable them instead.
- **No EPG:** `tvg-id` is passed through, but there is no `url-tvg` / XMLTV support.
- **No Xtream Codes API** (`player_api.php`). Apps that only support Xtream logins can't use the service.
- **Docker setup is development-grade:** code is bind-mounted, `APP_DEBUG` comes from `.env`, MySQL passwords are fixed in `docker-compose.yml`, and there's no HTTPS.
- **Unused:** Vite/Tailwind setup (`resources/css`, `resources/js`, `vite.config.js`).
- **Not covered by tests:** category CRUD, the user status toggle, and any browser-level JS (bulk selection, dialogs, copy button). Verified manually.
