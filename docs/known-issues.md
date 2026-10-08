# Known Issues

Remove entries when fixed (and note the fix in the CHANGELOG); add new ones as they're discovered.

## Bugs

_None currently known._

## Tooling

- **PHPStan baseline:** `phpstan-baseline.neon` lists 15 findings that are the analyser's limits, not bugs (date casts read as strings, Eloquent collection generics). Don't add to it: fix new findings. Shrink it when you touch those spots (`vendor/bin/phpstan analyse --generate-baseline`).
- **Browser tests need Google Chrome** on the machine (`channel: 'chrome'`). The Watch player (hls.js / mpegts.js from a CDN) is not covered.

## Design gaps / risks

- **Stream check is from the server's location:** `StreamCheckService` treats geo-blocked streams (403) as offline. A channel that plays in the viewer's country but not the server's is switched off (884 channels on 2026-10-07). Turn **Check streams** off on the source to keep those channels on, at the cost of keeping really dead ones too.
- **Xtream logins can't send stream headers unless the source hides its address:** channels that need `http_user_agent` / `http_referrer` play through the M3U link, but through the Xtream API only when the source has **Hide provider address** on (the server then sends the headers itself).
- **Bundled Macedonian lists go stale:** `database/seeders/playlists/*.m3u` are static. The stream check switches dead entries off, but new links have to be added by hand.
- **Watch player ties up a PHP worker:** a live MPEG-TS preview keeps one PHP-FPM worker busy while it plays (up to 30 minutes). On shared hosting with few workers, several open players can slow the panel down. HLS previews only use a worker per segment request. Suggested fix: lower `StreamPreviewService::MAX_SECONDS` there.
- **Some streams don't play in the browser:** HEVC, AC-3 audio, RTMP/UDP links and YouTube/Twitch pages work in VLC but not in the Watch player. The dialog says so and shows the address to copy.
- **Multi-genre categories:** iptv-org lists use `group-title` values like `General;News`, which become separate categories. Suggested fix: an option per source to keep only the first group.

- **Upstream URLs are exposed unless the source hides them:** `/play` redirects (302) to the provider URL, which often contains the provider username/password. Turn on **Hide provider address** on the source to play through the server instead (uses its bandwidth, see [playlist-and-streaming.md](features/playlist-and-streaming.md#hide-provider-address-stream-proxy)). Receivers are never proxied.
- **Stream proxy limits:** in `php` mode every MPEG-TS viewer holds a PHP worker; in `nginx` mode a running MPEG-TS stream can't keep its session alive (frees after 5 minutes); an HLS provider address without `m3u8` in it is treated as MPEG-TS in `nginx` mode; providers see one connection per viewer from the server's IP, so their connection limit applies to the whole server.
- **Provider credentials in `channels.stream_url` are plain text:** `sources.url` is encrypted (lost `APP_KEY` = lost addresses), but channel stream links are searched and updated in bulk, so they are not. A database backup still contains them: protect backups like passwords.
- **Credentials in query string** for `/m3u?username=&password=` (standard for IPTV, but they end up in server/proxy logs). Prefer the `/playlist/{token}.m3u` link.
- **Request limits are per minute, not per user:** `/play`, `/playlist` and `/epg` are limited per link + IP and per IP (`THROTTLE_*`), but `/live` and `/player_api.php` only have the wrong-password limit.
- **No Macedonian guide data:** the free iptv-epg.org Macedonia file only has "No Data" entries, so Macedonian channels show no programmes until a better XMLTV source is added on the TV guide page.
- **Guide times in the panel are UTC:** apps get exact timestamps, but *On now* on the channel form shows UTC (`APP_TIMEZONE` isn't set).
- **The receiver's own guide isn't used:** Enigma2 only has guide data for channels it has tuned. A `/epg.xml` relay endpoint could export it once the box collects EPG (e.g. with an EPG import plugin).
- **Relay links can be shared for 3 hours:** a signed receiver link keeps working until it expires (`RECEIVER_LINK_MINUTES`), so a viewer could pass it on. The tuner limit caps the damage. A shorter time means more failed reconnects.
- **Relay is wiped by image updates:** flashing OpenATV removes `/usr/bin/nebotv-relay.py` and the init script unless they are in the image backup ([receiver.md](receiver.md#after-an-openatv-image-update)).
- **Relay uses plain HTTP:** links carry signatures, not passwords, but streams aren't encrypted in transit.
- **Old admin password in git history:** commit `82be74b` put a real-looking admin password in `AdminSeeder.php`. The seeder now reads `.env`, but that password is still in the history: change it on any server that used it.
- **Devices are told apart by IP + user agent:** two identical TVs with the same app version behind one router look like one device and share a slot. Suggested fix: a per-device token.
- **No heartbeat without the proxy:** a session only stays "active" for 5 minutes after the last `/play` request, so long viewing frees the slot. With **Hide provider address** (`php` mode, or HLS) the session is kept alive while data flows.
- **Channels without a tvg-id are keyed by group + name:** if a provider renames such a channel or moves it to another group, it is re-created (new ID).
- **Bulk-deleted imported channels come back** on the next sync. Disable them instead.
- **Xtream Codes API is live TV only:** VOD, series, catch-up and EPG actions return empty data.
- **Xtream password checks are not cached:** every `player_api.php` and `/live` request runs `Hash::check` (bcrypt). Fine for a few users. With many, cache the result briefly or lower the bcrypt cost.
- **Docker setup is development-grade:** code is bind-mounted, `APP_DEBUG` comes from `.env`, MySQL passwords are fixed in `docker-compose.yml`, and there's no HTTPS.
- **Partly translated:** `/m3u`, `/playlist` and `/play` error bodies and `sources.last_error` are always English; numbers use English separators (`1,234`) in Macedonian too. Laravel's Macedonian plural rule treats 11 like 1 ("11 канал").
- **No Content-Security-Policy:** panel pages send basic security headers, but the views use inline scripts, so a CSP is not set yet.
