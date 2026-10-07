# NeboTV — Documentation

IPTV middleware built on Laravel 13. Admins manage the catalogue and subscribers; subscribers get a personal M3U playlist whose entries route through this service for authorisation, connection limiting and logging before being redirected to the real stream.

## Contents

| Doc | What it covers |
|---|---|
| [getting-started.md](getting-started.md) | Install, configure, seed, run, test |
| [deployment.md](deployment.md) | Shared hosting (e.g. Hostinger): upload, `.env`, one cron job, limits |
| [architecture.md](architecture.md) | Layers, request flow, conventions |
| [data-model.md](data-model.md) | Tables, columns, relationships |
| [routes.md](routes.md) | Every HTTP route |
| [features/authentication.md](features/authentication.md) | Admin login / logout |
| [features/localization.md](features/localization.md) | English / Macedonian panel: language menu with flags, translation files, rules for new text |
| [features/theme.md](features/theme.md) | Light / dark / system mode switch |
| [features/admin-panel.md](features/admin-panel.md) | Every admin page: dashboard, sources, channels, categories, bouquets, users, activity |
| [features/sources.md](features/sources.md) | Importing provider M3U playlists: sync rules, parser, scheduling |
| [features/receiver.md](features/receiver.md) | Satellite receiver (Octagon / Enigma2) through the NeboTV relay: signed links, tuner limit, setup guide |
| [features/alerts.md](features/alerts.md) | Stream check (switches dead channels off and back on), alerts in the header, on the dashboard and by email (Brevo) |
| [features/playlist-and-streaming.md](features/playlist-and-streaming.md) | M3U generation, tokens, play endpoint, connection limits, Xtream Codes login (server + username + password) |
| [known-issues.md](known-issues.md) | Bugs and gaps found so far |
| [CHANGELOG.md](CHANGELOG.md) | History of changes |

## Status (2026-10-07, v1)

Working end to end: import a provider M3U (URL or file) → channels, categories and a bouquet are created → create a user with that bouquet → paste their playlist link into a TV IPTV app → playback is authorised, limited and logged, then redirected to the provider stream.

Implemented: admin panel (redesigned) with dashboard, sources, channels (filters, bulk actions and a **Watch** player), categories, bouquets, users with playlist page, activity log. Panel in English and Macedonian (language menu with flags) and light/dark/system mode. Daily auto-refresh of sources, default sources on a fresh install, a stream check that switches dead channels off and back on, alerts for watched channels and sources (header bell, dashboard, email), login with an M3U link or with server + username + password (Xtream Codes API, live TV), login attempt limits, background task monitoring (`/health`, dashboard warning, alerts), satellite receiver channels through a relay on the receiver, session pruning, Docker stack, shared-hosting mode (one cron job). 223 tests (plus 9 relay tests), passing on SQLite and MySQL.

Not yet implemented: stream proxying for subscribers (`/play` still redirects; only the admin Watch player proxies), EPG, Xtream Codes VOD/series. See [known-issues.md](known-issues.md).

## Keeping docs current

Any change to behaviour, schema or routes must update these docs and add a line to [CHANGELOG.md](CHANGELOG.md). See `.claude/skills/update-docs/SKILL.md`.
