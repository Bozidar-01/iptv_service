# Playlist & Streaming

**Files:** `M3uController`, `PlayController`, `XtreamController`, `TokenService`, `StreamService`, `XtreamService`. Routes are in `routes/player.php`, loaded **without** the `web` middleware group: TV apps get no session, cookies or CSRF check.

## 1. Playlist

Two equivalent entry points:

| URL | Auth | Use |
|---|---|---|
| `GET /playlist/{playlist_token}.m3u` (`playlist`) | per-user token (`users.playlist_token`, 32 chars, created automatically, regenerable in the admin) | The link shown on the user page. Unknown token → `404 Playlist not found` |
| `GET /m3u?username=…&password=…` (`m3u`) | username + password (`Hash::check`) | Apps that want credentials. Wrong or missing → `401 Invalid credentials` |
| `GET\|POST /get.php?username=…&password=…&type=m3u_plus` (`xtream.playlist`) | same as `/m3u` | Xtream Codes playlist address. `type`/`output` are ignored |

Then, for both:
1. `status !== 'active'` → `403 Account disabled`; past `expiration_date` → `403 Account expired`.
2. **Playback token** (`TokenService::playbackToken`): reuse an access token that is still valid for more than 7 days, otherwise create one (`Str::random(32)`, valid 1 month). A playlist cached by a TV app keeps working until its next refresh.
3. Build the playlist (`TokenService::writeM3u`; `getM3u` returns it as a string for tests):
   - every **enabled** channel in any of the user's bouquets, **once** (de-duplicated across bouquets)
   - ordered by category name, then source order (`sort_order`), then name
   - read from the DB in chunks of 1000 and sent to the app in chunks of about 64 KB (`response()->stream`), so a 100k-channel playlist is never held in memory. No `Content-Length` header. Play URLs are made from one `route('play')` template by replacing the channel id and token

```
#EXTM3U
#EXTINF:-1 tvg-id="{tvg_id or channel.id}" tvg-name="{name}" tvg-logo="{logo_url}" group-title="{category or General}",{name}
{scheme://host:port}/play/{channel.id}/{token}{.ext}
```

- `{.ext}` mirrors the upstream URL's extension when it's `m3u8`, `ts`, `mp4`, `mkv`, `avi` or `mov`, so players pick the right demuxer.
- Play URLs use the host the playlist was requested from. TVs must request it via a LAN address, not `localhost`.
- Escaping: inside quoted attributes, `"` becomes `'`. Line breaks become spaces everywhere.
- Channels with `http_user_agent` / `http_referrer` get `#EXTVLCOPT:http-user-agent=…` / `#EXTVLCOPT:http-referrer=…` lines before their URL. TiviMate, OTT Navigator, VLC and Kodi send these on the play request and keep them through the redirect. The Xtream API has no way to pass them, so those channels may not play with a username/password login.
- The header carries the TV guide address (`x-tvg-url`), and `tvg-id` is the matched guide id ([epg.md](epg.md)).
- Channels whose stream is down but have a working backup redirect to the backup's stream (`Channel::playbackChannel()`).
- Satellite receiver channels redirect to a signed, expiring relay link instead of their stored URL (`ReceiverService::playUrl`, [receiver.md](receiver.md)).
- A failure half way through (e.g. the database goes away) can't change the already-sent `200`, so the app gets a short playlist; the error is reported (log / Sentry).
- Headers: `Content-Type: audio/x-mpegurl`, `Content-Disposition: attachment; filename="playlist.m3u"`.

## 2. Play: `GET /play/{channel_id}/{token}[.ext]`

1. The token (anything after the first `.` is ignored) is checked with `TokenService::validateToken` (exists and `expires_at > now`) → else `401`.
2. Channel lookup → else `404`.
3. `StreamService::isAllowed($user, $channel)` → else `403 Access denied or connection limit reached`:
   - user `isActive()` (status active and not expired)
   - channel `is_active`
   - channel belongs to at least one of the user's bouquets
   - **connection limit:** one slot per **device** (client IP + user agent). Count the user's `active_sessions` with `last_activity_at` within the last `StreamService::SESSION_TIMEOUT_MINUTES` (**5**), leaving out the requesting device's own session. If that count is ≥ `max_connections`, deny. So a device can always switch channels, but with a limit of 1 a second device (another TV in the same home, or another app on the same TV) is refused, even on the same channel.
4. `StreamService::getStreamUrl`:
   - inserts a `stream_logs` row (IP, user agent, time)
   - upserts the device's `active_sessions` row (user + IP + user agent): `channel_id` = this channel, `last_activity_at` = now; `started_at` is reset for a new device, a channel change, or when the session had gone stale
5. `302` redirect to `channel.stream_url`, or, for a source with **Hide provider address** on, the stream itself (see [Hide provider address](#hide-provider-address-stream-proxy)).

### Hide provider address (stream proxy)

`StreamProxyService`. By default a play request redirects the app to the provider address, which often contains the provider's username and password, so anyone with a playlist can read it. For a source with **Hide provider address** (`sources.proxy_streams`) turned on, `/play` and Xtream `/live` pass the stream on instead: the app only ever sees this server. It also sends the channel's own `http_user_agent` / `http_referrer` to the provider (so those channels now work through the Xtream API too) and keeps the viewing alive while data flows. **All video then uses this server's internet connection**, so only turn it on where you need it.

It works only when `STREAM_PROXY` in `.env` is not `off`:

| `STREAM_PROXY` | How | Good for |
|---|---|---|
| `off` (default) | redirect, as before | |
| `php` | PHP fetches the stream and passes it on (`StreamPreviewService::proxy`) | any host, a few viewers. Every viewer of a live MPEG-TS stream keeps one PHP worker busy (no limit except the host's), so on shared hosting a few viewers can use them all |
| `nginx` | PHP checks access, logs and answers `X-Accel-Redirect: /_proxy`; nginx fetches the stream and passes it on | a VPS or the Docker setup. No PHP worker is held. Needs `docker/nginx/stream-proxy.conf` (see [deployment.md](../deployment.md#hide-provider-address-on-your-own-server)). The nginx rules announce themselves to PHP (`STREAM_PROXY_ENGINE`); without them the mode falls back to `php` rather than leaking the address in a header |

- **Private addresses are refused** (`PublicAddress`): a playlist could otherwise point a channel at `127.0.0.1`, the database, or `169.254.169.254` and viewers would read the answer. The first address and every redirect in `php` mode are checked (names are resolved); nginx follows its own redirects unchecked, so run it with limited network access. Set `PROXY_ALLOW_PRIVATE=true` only if a provider really is on your local network.
- **`HEAD` requests** get an empty `200` and never open the provider stream. Starting a stream through Xtream `/live` has the same request limit as `/play` (`THROTTLE_PER_TOKEN` per username + IP).
- **Parallel streams (`php` mode):** a device (IP + user agent) may run `PROXY_STREAMS_PER_DEVICE` (3) running streams at once; more get `429` + `Retry-After`. Each one holds a PHP worker. The count lives in the cache, is refreshed by the heartbeat and expires by itself if a worker dies.
- **Part links** (`u`) are made for one channel and one user and stop working after 6 hours; a provider error or exception message is never shown to the viewer (generic 502). In `nginx` mode provider errors (4xx/5xx) become a plain 502, and the provider's `Via`, `Link`, `Content-Location`, `Alt-Svc`, `Refresh` and similar headers are removed too.
- **Receiver channels are never proxied** (they already use signed relay links), and neither are non-http addresses.
- **HLS** (an address containing `m3u8`): always fetched by PHP, because the playlist has to be rewritten. Every segment, key and sub-playlist address becomes `/stream/{channel}/{token}?u=…`, where `u` is an **encrypted** payload (channel id + provider address, `APP_KEY`), so the provider address and any token inside it can't be read from the playlist. In `nginx` mode the segments themselves are passed on by nginx. For an address without `m3u8`, `nginx` mode first reads the first bytes from the provider once (a `Range: bytes=0-511` request, answer remembered for an hour) and rewrites it in PHP if it is a playlist; otherwise it is passed on by nginx. That adds one short extra connection to the provider the first time.
- **`GET /stream/{channel_id}/{token}?u=`** (`stream`): same token, bouquet and connection-limit checks as `/play`, no new `stream_logs` row, but it refreshes the device's session (`StreamService::touch`). Separate, higher request limit: `THROTTLE_STREAM_PER_TOKEN` (600 a minute per link + IP).
- **Session heartbeat:** in `php` mode a running MPEG-TS stream refreshes its session every 30 seconds and may run up to 24 hours; HLS refreshes it with every segment. In `nginx` mode a running MPEG-TS stream can't refresh it, so its slot frees after 5 minutes as without the proxy.
- Provider redirects are followed (nginx: up to three, relative ones too; PHP: five). The provider's `Location`, `Set-Cookie` and `Server` headers are never passed on. A provider that limits connections sees one connection per viewer, from this server's IP.

## 3. Xtream Codes API (login with server + username + password)

Lets apps like IPTV Smarters, TiviMate and XCIPTV log in with **server URL** (`scheme://host:port`, no path), **username** and **password** (the user's panel password). The user page shows these under **Log in with username and password**, with host and port separately for apps that ask for them. Live TV only.

| Endpoint | Behaviour |
|---|---|
| `GET\|POST /player_api.php?username=&password=` (`xtream.api`) | Wrong or missing credentials → `{"user_info":{"auth":0}}` (HTTP 200, as the apps expect). Otherwise `user_info` (`auth` 1, `status` `Active`/`Disabled`/`Expired`, `exp_date` unix or null, `max_connections`, `active_cons`, `allowed_output_formats` `["m3u8","ts"]`) and `server_info` (`url` = request host, `port` = the port the request came in on and `https_port` (443 unless the request was https on another port), `server_protocol` = the request's, so https is never paired with port 80, `timezone`, `timestamp_now`, `time_now`) |
| `…&action=get_live_categories` | Categories with at least one channel the user can watch, by name. Uncategorized channels → `category_id` `"0"`, "General" |
| `…&action=get_live_streams[&category_id=]` | The user's channels (`Channel::availableTo()`, playlist order), streamed as JSON: `num`, `name`, `stream_type` `live`, `stream_id` = channel id, `stream_icon`, `epg_channel_id` = `tvg_id`, `category_id`, … |
| `…&action=get_vod_*`, `get_series*`, anything else | `[]` |
| `…&action=get_short_epg` / `get_simple_data_table` | The channel's programmes ([epg.md](epg.md)) |
| `GET /live/{username}/{password}/{id}.{ts\|m3u8}` (`xtream.live`) | Same as `/play`: `401` wrong credentials, `404` unknown channel, `403` no access / disabled / expired / connection limit, otherwise logs, updates the session and `302` to the upstream |
| `GET /xmltv.php` (`xtream.xmltv`) | The user's TV guide as XMLTV ([epg.md](epg.md)) |

Disabled and expired accounts can still log in, so the app shows the status, but every action returns `[]`.

**Web server:** these are Laravel routes, not files. The Docker nginx config sends every `*.php` URL to `public/index.php` (`docker/nginx/default.conf`). Apache (shared hosting) needs nothing extra: `public/.htaccess` already rewrites missing files to `index.php`.

The password is checked with `Hash::check` on every API call and every `/live` request. **Login limit:** after `XtreamService::MAX_FAILED_ATTEMPTS` (20) wrong username/password attempts from one IP, every login from that IP fails for `LOCKOUT_SECONDS` (10 minutes), even with the right password. This applies to `/m3u`, `/get.php`, `/player_api.php` and `/live`. Successful logins don't count. Stream URLs contain the password in plain text, as in every Xtream Codes server.

### Session cleanup
`ActiveSession` uses Laravel's `Prunable`. Rows idle longer than the timeout are deleted by `model:prune`, scheduled every 15 minutes in `routes/console.php` (the `scheduler` container, or `php artisan schedule:work`).

### Notes
- A "device" is client IP + user agent. Two identical TVs running the same app version behind one router look like one device.
- Activity is only recorded when a play URL is requested. There's no heartbeat, so a long-running stream "expires" from the connection count after 5 minutes.
- There is no proxying: after the redirect the player talks to the upstream directly, so the upstream URL (including any provider credentials in it) is visible to the client. `getStreamUrl` is the place to add proxy/signed-URL logic.
