# IPTV Service — Documentation

IPTV middleware built on Laravel 13. Admins manage the catalogue and subscribers; subscribers get a personal M3U playlist whose entries route through this service for authorisation, connection limiting and logging before being redirected to the real stream.

## Contents

| Doc | What it covers |
|---|---|
| [getting-started.md](getting-started.md) | Install, configure, seed, run, test |
| [architecture.md](architecture.md) | Layers, request flow, conventions |
| [data-model.md](data-model.md) | Tables, columns, relationships |
| [routes.md](routes.md) | Every HTTP route |
| [features/authentication.md](features/authentication.md) | Admin login / logout |
| [features/admin-panel.md](features/admin-panel.md) | Dashboard, users, channels, categories, bouquets, logs |
| [features/playlist-and-streaming.md](features/playlist-and-streaming.md) | M3U generation, tokens, play endpoint, connection limits |
| [known-issues.md](known-issues.md) | Bugs and gaps found so far |
| [CHANGELOG.md](CHANGELOG.md) | History of changes |

## Status (2026-10-07)

Implemented: admin auth, admin CRUD for users/channels/categories/bouquets, user status toggle, dashboard stats, stream log viewer, M3U playlist endpoint, play/redirect endpoint with access checks and session tracking.

Also: stale-session pruning and a feature test suite (47 tests).

Not yet implemented: stream proxying (currently a redirect), EPG, Xtream Codes API, rate limiting, admin role middleware.

## Keeping docs current

Any change to behaviour, schema or routes must update these docs and add a line to [CHANGELOG.md](CHANGELOG.md). See `.claude/skills/update-docs/SKILL.md`.
