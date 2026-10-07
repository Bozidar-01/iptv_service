# Playlist & Streaming

**Files:** `M3uController`, `PlayController`, `TokenService`, `StreamService`

## 1. Playlist

Two equivalent entry points:

| URL | Auth | Use |
|---|---|---|
| `GET /playlist/{playlist_token}.m3u` (`playlist`) | per-user token (`users.playlist_token`, 32 chars, created automatically, regenerable in the admin) | The link shown on the user page. Unknown token → `404 Playlist not found` |
| `GET /m3u?username=…&password=…` (`m3u`) | username + password (`Hash::check`) | Apps that want credentials. Wrong → `401 Invalid credentials` |

Then, for both:
1. `status !== 'active'` → `403 Account disabled`; past `expiration_date` → `403 Account expired`.
2. **Playback token** (`TokenService::playbackToken`): reuse an access token that is still valid for more than 7 days, otherwise create one (`Str::random(32)`, valid 1 month). A playlist cached by a TV app keeps working until its next refresh.
3. Build the playlist (`TokenService::getM3u`):
   - every **enabled** channel in any of the user's bouquets, **once** (de-duplicated across bouquets)
   - ordered by category name, then source order (`sort_order`), then name
   - streamed from the DB in chunks of 1000

```
#EXTM3U
#EXTINF:-1 tvg-id="{tvg_id or channel.id}" tvg-name="{name}" tvg-logo="{logo_url}" group-title="{category or General}",{name}
{scheme://host:port}/play/{channel.id}/{token}{.ext}
```

- `{.ext}` mirrors the upstream URL's extension when it's `m3u8`, `ts`, `mp4`, `mkv`, `avi` or `mov`, so players pick the right demuxer.
- Play URLs use the host the playlist was requested from. TVs must request it via a LAN address, not `localhost`.
- Escaping: inside quoted attributes, `"` becomes `'`. Line breaks become spaces everywhere.
- Headers: `Content-Type: audio/x-mpegurl`, `Content-Disposition: attachment; filename="playlist.m3u"`.

## 2. Play: `GET /play/{channel_id}/{token}[.ext]`

1. The token (anything after the first `.` is ignored) is checked with `TokenService::validateToken` (exists and `expires_at > now`) → else `401`.
2. Channel lookup → else `404`.
3. `StreamService::isAllowed($user, $channel)` → else `403 Access denied or connection limit reached`:
   - user `isActive()` (status active and not expired)
   - channel `is_active`
   - channel belongs to at least one of the user's bouquets
   - **connection limit:** count the user's `active_sessions` with `last_activity_at` within the last `StreamService::SESSION_TIMEOUT_MINUTES` (**5**). If the count is ≥ `max_connections`, the request is allowed only if one of those *active* sessions is for this same channel (re-opening what you're already watching doesn't use a slot).
4. `StreamService::getStreamUrl`:
   - inserts a `stream_logs` row (IP, user agent, time)
   - upserts `active_sessions` for (user, channel): `last_activity_at` = now; `started_at` is set only for a new session or when the previous one had gone stale
5. `302` redirect to `channel.stream_url`.

### Session cleanup
`ActiveSession` uses Laravel's `Prunable`. Rows idle longer than the timeout are deleted by `model:prune`, scheduled every 15 minutes in `routes/console.php` (the `scheduler` container, or `php artisan schedule:work`).

### Notes
- Activity is only recorded when a play URL is requested. There's no heartbeat, so a long-running stream "expires" from the connection count after 5 minutes.
- There is no proxying: after the redirect the player talks to the upstream directly, so the upstream URL (including any provider credentials in it) is visible to the client. `getStreamUrl` is the place to add proxy/signed-URL logic.
