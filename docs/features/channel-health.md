# Channel health and backups

How NeboTV keeps channels playing: a closer look at HLS streams, a reliability score, what viewers run into, and ranked backups that are used at check time and while a viewer is trying to play. The basic stream check (online / offline, switching off, retries, alerts) is in [alerts.md](alerts.md).

**Files:** `StreamCheckService` (`inspectHls`), `ChannelHealthService`, `ChannelFailoverService`, `StreamService` (retry choice), table `channel_check_days`.

## Closer look at HLS streams (`StreamCheckService::inspectHls`)

Every HLS stream the basic check finds working gets a second look in the same job:

- **Video playlist:** for a master playlist, the playlist of the best picture size (highest `BANDWIDTH`) is read, else the stream's own playlist. If that one answers with an error or lists no video (no `#EXTINF` segment), the next size is tried, up to `MAX_VARIANTS` (3), as players do. Only when they all fail with an HTTP error or an empty playlist is the channel offline with `stream_error = no-video` ("The playlist answers, but the video in it does not"). No answer at all (a provider may limit connections) is not judged.
- **Frozen picture:** the playlist's `EXT-X-MEDIA-SEQUENCE` is saved (`channels.hls_sequence`). When the next check, at least `max(60 s, 3 × target duration)` later, finds a live playlist (no `#EXT-X-ENDLIST`) still starting at the same piece of video, the picture has stopped: offline with `stream_error = frozen` ("The picture has stopped: the stream sends no new video"). Playlists without the sequence tag are not judged.
- **Speed:** for channels people watch (played in the last 7 days, or alerts on), the newest piece of video is downloaded (first 1 MB). From the time to the first byte and the rate of the rest, the time to download the whole piece is worked out and compared to how long it plays: `channels.stream_speed`, percent (`StreamCheckService::speedPercent`). 100 = just in time; below `ChannelFailoverService::SLOW_BELOW` (100) the channel is **slow**: viewers wait. A piece that does not arrive counts as 0 (slow, but not switched off).
- Long playlists are read up to 64 KB (the cut-off last line is dropped). Failed closer looks are tried again once after 5 s.
- Tried on 150 real channels from the dev data: 3 truly dead `no-video` (all sizes 404/410), 2 truly frozen of 100 checked twice, 7 slow (far-away servers).

## Reliability (`ChannelHealthService`)

- Every check counts per channel and day in `channel_check_days` (`ok`, `failed`, `slow`). `channels.reliability` is the share of working checks over the last 7 days (`DAYS`), worked out after each check (one query per 1,000 channels). History older than 30 days is deleted daily (`iptv:channel-upkeep`).
- Shown on the Channels list ("98% working"), filter **Often not working** (under 90%), on the channel edit page (*How well it works*) and in Find duplicates.

## What viewers run into

- A device that asks for the same channel `RETRY_PLAYS` (3) times within `RETRY_SECONDS` (60) is retrying because it does not play. The first time, it counts as **viewer trouble** (`channel_check_days.viewer_trouble`, once per viewer, channel and day) and a quick check of that channel is queued (at most every 10 minutes per channel; not for receiver channels or sources with Check streams off).
- Channels with trouble from `TROUBLE_VIEWERS` (2) viewers today and yesterday are marked **Viewers had trouble** on the Channels list (also a filter). This helps where the server's own check says the stream works but viewers can't play it (another country, or a stream that answers but doesn't play).
- HLS streams whose address is a media playlist (no measured picture size) are left out: players fetch that playlist through the play link every few seconds while it plays fine.

## Backups (`ChannelFailoverService`)

Copies of a channel are the admin's chosen **Backup channel**, the hidden **backup copies** Find duplicates kept for it (`backup_for_id`), and every other channel with the same `tvg-id`. A copy must be enabled, working and not on a backup itself. They are ranked: the chosen backup first, then not slow, then most reliable (unknown = 50%), then best picture, then lowest id. The edit page lists them as *Backups, best first*.

- **At check time:** a channel that is down plays its best copy (`using_backup_id`) instead of being switched off. A **slow** channel moves to a copy that is not slow, and stays on. Both go back to their own stream when a check finds it working well. Channels playing a backup are checked every 15 minutes with the watched channels.
- **While a viewer tries to play:** from the third request in a minute, the device is sent to the next copy (`ChannelFailoverService::forRetry`: copies best first, then the channel's own stream again, so one bad copy doesn't keep anyone stuck). The choice is kept for that device for `StreamService::RETRY_CHOICE_MINUTES` (10), also for the parts of a stream played through the server. Other viewers are not affected. Applies to `/play`, Xtream `/live` and catch-up.
