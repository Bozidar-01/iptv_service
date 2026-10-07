---
name: run-app
description: Start, inspect and debug the IPTV service's Docker stack (nginx, PHP-FPM, MySQL, queue worker, scheduler), run artisan/tests inside it, and verify changes in the browser. Use when asked to run, start, restart or check the app, see logs, debug an import, or confirm a change works in the real UI.
---

# Run the app

## Start / stop
```bash
docker compose up -d --build     # first time, or after changing Dockerfile / docker/*
docker compose up -d             # otherwise
docker compose ps                # all of app, queue, scheduler, web, db should be Up (db healthy)
docker compose down              # stop, keeps data
```
If `docker info` fails, Docker Desktop isn't running (`open -a Docker`, then wait for `docker info` to succeed).

The project is bind-mounted, so PHP/Blade/CSS changes apply immediately. `public/css/admin.css` is cache-busted by its mtime.

## URLs and logins
- Panel: http://localhost:8000. Admin `admin` / `admin123` (from `AdminSeeder`, seeded on container start; `ADMIN_USERNAME` / `ADMIN_PASSWORD` in `.env` override it). This machine's DB may have other admins from earlier seeds.
- From TVs and phones: `http://<LAN IP>:8000` (`ipconfig getifaddr en0`).
- MySQL from the host: `127.0.0.1:3307`, `iptv` / `iptv`, db `iptv_service`.

## Inside the containers
```bash
docker compose exec app php artisan test                   # safe: forced to in-memory SQLite
docker compose exec app php artisan tinker --execute '...'
docker compose exec app php artisan iptv:sync-sources --now
docker compose exec app php artisan route:list --except-vendor
docker compose logs -f queue        # import jobs (RUNNING / DONE / FAIL)
docker compose logs app             # migrations on start, PHP-FPM errors
tail -f storage/logs/laravel.log    # Laravel exceptions (same file on host and in containers)
docker compose restart queue        # after changing job/service code: workers keep old code in memory
```

## Verifying in the browser
1. Open http://localhost:8000/login in the built-in browser and sign in with the seeded admin.
2. Check desktop (~1366 px) **and** mobile (375 px) widths. Reset the viewport afterwards.
   For UI text changes, also switch to **Македонски** with the language menu (flag) at the bottom of the sidebar (sets the `locale` cookie) and check the page; switch back to English when done.
   For styling changes, check **light and dark** with the sun/moon buttons next to it (sets the `theme` cookie); set it back to the monitor icon (system) when done.
3. Import test: add a source with a small public playlist, e.g. `https://iptv-org.github.io/iptv/countries/mk.m3u`, and check the queue log and the Sources page.
4. Playlist test: create a user with the bouquet, then `curl` their `/playlist/{token}.m3u` and one `/play/...` URL (expect `302`).

## Reset
`docker compose down -v` deletes the database volume. Only do this when the user asks: it removes all their data.
