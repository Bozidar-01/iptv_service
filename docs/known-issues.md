# Known Issues

Remove entries when fixed (and note the fix in the CHANGELOG); add new ones as they're discovered.

## Bugs

_None currently known._

## Tooling

- **PHPStan baseline:** `phpstan-baseline.neon` lists 2 findings that are the analyser's limits, not bugs (Eloquent collection generics, a nullsafe on a relation). Date columns of a model are declared with `@property` in the model's docblock, because the analyser doesn't read the `casts()` method: do the same for new date columns. Don't add to it: fix new findings. Shrink it when you touch those spots (`vendor/bin/phpstan analyse --generate-baseline`).
- **Browser tests need Google Chrome** on the machine (`channel: 'chrome'`). The Watch player (hls.js / mpegts.js from a CDN) is not covered.

## Design gaps / risks

- **Stream check is from the server's location:** `StreamCheckService` treats geo-blocked streams (403) as offline. A channel that plays in the viewer's country but not the server's is switched off (884 channels on 2026-10-07). Turn **Check streams** off on the source to keep those channels on, at the cost of keeping really dead ones too.
- **Xtream logins can't send stream headers unless the source hides its address:** channels that need `http_user_agent` / `http_referrer` play through the M3U link, but through the Xtream API only when the source has **Hide provider address** on (the server then sends the headers itself).
- **Bundled Macedonian lists go stale:** `database/seeders/playlists/*.m3u` are static. The stream check switches dead entries off, but new links have to be added by hand.
- **Watch player ties up a PHP worker:** a live MPEG-TS preview keeps one PHP-FPM worker busy while it plays (up to 30 minutes). On shared hosting with few workers, several open players can slow the panel down. HLS previews only use a worker per segment request. Suggested fix: lower `StreamPreviewService::MAX_SECONDS` there.
- **Some streams don't play in the browser:** HEVC, AC-3 audio, RTMP/UDP links and YouTube/Twitch pages work in VLC but not in the Watch player. The dialog says so and shows the address to copy.
- **Multi-genre categories:** iptv-org lists use `group-title` values like `General;News`, which become separate categories. Suggested fix: an option per source to keep only the first group.

- **Upstream URLs are exposed unless the source hides them:** `/play` redirects (302) to the provider URL, which often contains the provider username/password. Turn on **Hide provider address** on the source to play through the server instead (uses its bandwidth, see [playlist-and-streaming.md](features/playlist-and-streaming.md#hide-provider-address-stream-proxy)). Receivers are never proxied.
- **Stream proxy limits:** in `nginx` mode a long MPEG-TS stream frees its device slot after 5 minutes, so another device can start meanwhile and `max_connections` is not enforced for it; the extra probe connection to the provider can matter for providers with a connection limit of 1; nginx's own redirects are not checked against private addresses; HLS byte-range playlists, DASH and playlists over 2 MB are not supported; providers see one connection per viewer from the server's IP, so their connection limit applies to the whole server.
- **Provider credentials in `channels.stream_url` are plain text:** `sources.url` is encrypted (lost `APP_KEY` = lost addresses), but channel stream links are searched and updated in bulk, so they are not. A database backup still contains them: protect backups like passwords.
- **Credentials in query string** for `/m3u?username=&password=` (standard for IPTV, but they end up in server/proxy logs). Prefer the `/playlist/{token}.m3u` link.
- **Request limits are per minute, not per user:** `/play`, `/playlist` and `/epg` are limited per link + IP and per IP (`THROTTLE_*`), and the other app addresses too (`/m3u`, `/get.php`, `/xmltv.php`, `/live`, `/player_api.php`), but a hotel or block of flats with many TVs behind one router can still reach the per-IP limits (raise `THROTTLE_PER_IP` / `THROTTLE_API_PER_IP`).
- **No Macedonian guide data:** the free iptv-epg.org Macedonia file only has "No Data" entries, so Macedonian channels show no programmes until a better XMLTV source is added on the TV guide page.
- **Guide times in the panel are UTC:** apps get exact timestamps, but *On now* on the channel form shows UTC (`APP_TIMEZONE` isn't set).
- **The receiver's own guide overloads a box with little memory:** on the Octagon (about 240 MB free, 3,521 channels in 274 bouquets) reading it, even one channel at a time, made the box reboot, so it runs with `guide = no`. Its channels get their guide from the TV guide page by name (52% on the dev data). Rytec's satellite guides were checked on 2026-10-19: they would add about 6 points (mostly German channels), from one working mirror in `.xz` (which PHP can't unpack without the `xz` program), and are meant for personal use, so they are not built in.
- **The filled-in relay settings were committed once** (`bb61fbd`, removed in `02d9577`): the relay secret, the box password and the DuckDNS token in them are in the git history. Change all three (relay secret in the panel's receiver source and `/etc/nebotv-relay.conf`).
- **Sales are recorded where months are added, not what was charged:** an admin editing a reseller's user records the months at the reseller's price (the user's seller), and moving a user to another reseller does not move earlier sales.
- **Relay links can be shared for 3 hours:** a signed receiver link keeps working until it expires (`RECEIVER_LINK_MINUTES`), so a viewer could pass it on. The tuner limit caps the damage. A shorter time means more failed reconnects.
- **Relay is wiped by image updates:** flashing OpenATV removes `/usr/bin/nebotv-relay.py` and the init script unless they are in the image backup ([receiver.md](receiver.md#after-an-openatv-image-update)).
- **Relay uses plain HTTP:** links carry signatures, not passwords, but streams aren't encrypted in transit.
- **Old admin password in git history:** commit `82be74b` put a real-looking admin password in `AdminSeeder.php`. The seeder now reads `.env`, but that password is still in the history: change it on any server that used it.
- **Xtream apps are told apart by IP + user agent:** M3U playlist links carry a device key, but Xtream apps build their own links, so two identical TVs with the same Xtream app behind one router still share a slot.
- **Device keys and a playlist reload:** an app that loads its playlist again gets a new key and takes over its old slot only when that slot's last play was before the reload. A twin TV that last switched channel before the other TV reloaded is treated as the same device until it plays again (as before device keys).
- **No heartbeat without the proxy:** a session only stays "active" for 5 minutes after the last `/play` request, so long viewing frees the slot. With **Hide provider address** (`php` mode, or HLS) the session is kept alive while data flows.
- **Channels without a tvg-id are keyed by group + name:** if a provider renames such a channel or moves it to another group, it is re-created (new ID).
- **Bulk-deleted imported channels come back** on the next sync. Disable them instead.
- **Xtream Codes API is live TV only:** VOD and series actions return empty data. Catch-up works where the provider's playlist says it has an archive.
- **Catch-up start times for Xtream providers are sent in UTC:** Xtream servers read the start in their own time zone, which NeboTV doesn't know (`CatchupService::xtreamUrl`). A provider on another time zone starts the programme off by the difference. Suggested fix: a time zone per source.
- **Speed is measured from the server:** `stream_speed` says how fast video reaches the server, not the viewer. A stream far from the server can look slow while it plays fine nearby (and the other way round); slow channels only move to a faster copy, they are never switched off.
- **Ready lists can lag up to 10 minutes:** `ListCacheService` sees changes by counts and last-change times in whole seconds; a change in the same second as the previous one, or a raw query that doesn't touch `updated_at`, shows when the list is made again (at most `MAX_AGE`, 10 minutes). Call `ListCacheService::bump()` after such changes.
- **Docker setup is development-grade:** code is bind-mounted, `APP_DEBUG` comes from `.env`, MySQL passwords are fixed in `docker-compose.yml`, and there's no HTTPS.
- **Partly translated:** `/m3u`, `/playlist` and `/play` error bodies and `sources.last_error` are always English; numbers use English separators (`1,234`) in Macedonian too. Laravel's Macedonian plural rule treats 11 like 1 ("11 канал").
- **No Content-Security-Policy:** panel pages send basic security headers, but the views use inline scripts, so a CSP is not set yet.
