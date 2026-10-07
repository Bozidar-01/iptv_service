# Known Issues

Remove entries when fixed (and note the fix in the CHANGELOG); add new ones as they're discovered.

## Bugs

_None currently known._ The bugs found in the 2026-10-07 review were fixed the same day; see the CHANGELOG.

## Tooling

- **Pint is not runnable:** `vendor/bin/pint` fails because `vendor/laravel/pint/builds/pint` is missing. Run `composer install` to restore it.

## Design gaps / risks

- **Credentials in query string** for `/m3u` (standard for IPTV, but they end up in server/proxy logs).
- **No rate limiting** on `/login`, `/m3u`, `/play`.
- **Admin group only checks `auth`**, with no role middleware. Safe today only because non-admins are logged out at login.
- **Upstream URL is exposed** to clients via the redirect (no proxy).
- **No heartbeat:** a session only stays "active" for 5 minutes after the last `/play` request, so long viewing frees the slot.
- **Stale-session pruning needs the scheduler** (`php artisan schedule:work` locally, or a cron running `schedule:run` in production).
- **Dashboard trend labels** are hard-coded.
- **Unused files:** `resources/views/*/partials/_form.blade.php` (Tailwind-based), `resources/views/welcome.blade.php`, Vite/Tailwind setup.
- **Partial test coverage:** auth, playlist, play, channel CRUD, validation display and seeders are covered. User and bouquet CRUD, the user status toggle and the dashboard numbers are not.
