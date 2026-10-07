# NeboTV

A self-hosted IPTV panel. Import channels from your providers' M3U playlists, organise them into bouquets, create users, and give each user one playlist link to paste into an IPTV app on their TV (TiviMate, IPTV Smarters, OTT Navigator, VLC…).

Built with Laravel 13 / PHP 8.4 and MySQL.

## Quick start (Docker)

```bash
docker compose up -d --build
```

Then open **http://localhost:8000** and sign in as `admin` / `admin123`, or the `ADMIN_USERNAME` / `ADMIN_PASSWORD` from `.env` (change it under Users).

1. **Sources → Add source**: paste your provider's M3U URL or upload a file. Channels import in the background.
2. **Users → New user**: tick the bouquet the source created.
3. Copy the user's **playlist link** into the IPTV app on your TV. Open the panel via your computer's network IP (e.g. `http://192.168.1.20:8000`) so the link is reachable from the TV.

Docker runs the web server, PHP, MySQL, the queue worker (imports) and the scheduler (daily refresh, session cleanup).

Shared hosting (e.g. Hostinger) works too, with a single cron job: see [docs/deployment.md](docs/deployment.md).

## Documentation

See [docs/](docs/README.md): setup, architecture, data model, routes and every feature.

## Development

```bash
docker compose exec app php artisan test     # tests (always in-memory SQLite)
docker compose logs -f queue                 # watch imports
docker compose exec app php artisan tinker
```
