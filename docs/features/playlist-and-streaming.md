# Playlist & Streaming

**Files:** `M3uController`, `PlayController`, `TokenService`, `StreamService`

## 1. Playlist — `GET /m3u?username=…&password=…`

1. Validates both query params are present.
2. Looks the user up by username and checks the password with `Hash::check` → `401 Invalid credentials`.
3. `status !== 'active'` → `403 Account disabled`; past `expiration_date` → `403 Account expired`.
4. Reuses an unexpired access token for the user, or creates one (`Str::random(32)`, expires in 1 month).
5. Builds the playlist (`TokenService::getM3u`): for each bouquet assigned to the user, for each **active** channel:

```
#EXTM3U
#EXTINF:-1 tvg-id="{channel.id}" tvg-name="{name}" tvg-logo="{logo_url}" group-title="{category or General}",{name}
{APP_URL}/play/{channel.id}/{token}
```

Response headers: `Content-Type: audio/x-mpegurl`, `Content-Disposition: attachment; filename="playlist.m3u"`.

Escaping: inside quoted attributes, `"` is replaced with `'`. In all values, line breaks are collapsed to spaces so each entry stays on two lines.

A channel that's in several of the user's bouquets appears more than once.

## 2. Play — `GET /play/{channel_id}/{token}`

1. `TokenService::validateToken` (exists and `expires_at > now`) → else `401`.
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
`ActiveSession` uses Laravel's `Prunable`. Rows idle longer than the timeout are deleted by `model:prune`, scheduled every 15 minutes in `routes/console.php`. The scheduler must be running (`php artisan schedule:work` locally, or cron `schedule:run`).

### Notes
- Activity is only recorded when a play URL is requested. There's no heartbeat, so a long-running stream "expires" from the connection count after 5 minutes.
- There is no proxying yet: the real upstream URL is exposed to the client after the redirect. `getStreamUrl` is the place to add proxy/signed-URL logic.
