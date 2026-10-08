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
5. `302` redirect to `channel.stream_url`.

## 3. Xtream Codes API (login with server + username + password)

Lets apps like IPTV Smarters, TiviMate and XCIPTV log in with **server URL** (`scheme://host:port`, no path), **username** and **password** (the user's panel password). The user page shows these under **Log in with username and password**, with host and port separately for apps that ask for them. Live TV only.

| Endpoint | Behaviour |
|---|---|
| `GET\|POST /player_api.php?username=&password=` (`xtream.api`) | Wrong or missing credentials → `{"user_info":{"auth":0}}` (HTTP 200, as the apps expect). Otherwise `user_info` (`auth` 1, `status` `Active`/`Disabled`/`Expired`, `exp_date` unix or null, `max_connections`, `active_cons`, `allowed_output_formats` `["m3u8","ts"]`) and `server_info` (`url` = request host, `port`/`https_port`, `server_protocol`, `timezone`, `timestamp_now`, `time_now`) |
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
