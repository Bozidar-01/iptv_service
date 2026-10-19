# Playlist options

**Admin → Playlist options** (`admin.playlist-options.*`, admins only; also a button on the Channels page): what viewers see in their apps. Changes show when an app loads its list again.

**Files:** `PlaylistOptionsController`, `PlaylistOptionsService`, `ChannelNumberService`, `ChannelCatalogService`, `CatchupService`, `CatchupController`, `app/Jobs/FillChannelInfo.php`, `app/Console/Commands/ChannelUpkeepCommand.php`. Settings are one `app_settings` row (`playlist_options`).

## Channel names

- **Show the picture quality in channel names** (`quality_in_names`, off by default): "Rai 1" becomes "Rai 1 FHD". The label is 4K / FHD / HD / SD from the measured picture height (`quality_height`), else the tag in the name (`PlaylistOptionsService::label`). Tags already in the name (HD, FHD, 1080p…) are replaced. Used for the M3U name and `tvg-name` and the Xtream `name`.
- **List only the better copy of a channel** (`hide_worse_copies`, off by default): channels with the same `tvg-id` are copies. The best is the working copy with the best picture (then most reliable, then lowest id). Another copy gets `better_copy_id` = the best one when the best has a strictly better picture and is in **every** bouquet the other one is in (nobody loses the channel). Such copies are left out of M3U playlists, Xtream lists and categories (`Channel::scopeListedFor`), unless the better copy is hidden for the viewer. They still play (links saved in apps keep working) and still serve as backups. Worked out again when the option is saved, after each full stream check and daily (`PlaylistOptionsService::refreshBetterCopies`).

## Channel numbers

- Every channel has a fixed `channels.number`, sent as `tvg-chno` in the M3U playlist and as `num` in the Xtream list, so a number stays the same channel after every reload and viewers can use the remote's number keys.
- Numbers never change by themselves. New channels get the next free numbers: after each import (`ChannelNumberService::fillMissing`), when added by hand (`Channel::booted`), and daily. The migration numbered all existing channels in playlist order.
- **Number all channels again** (`POST admin/playlist-options/renumber`): 1, 2, 3… in playlist order (category, position in the source, name). One channel's number can be typed on the channel form (empty = keep it).

## Logos and guide IDs (`ChannelCatalogService`)

- From the free [iptv-org channel database](https://github.com/iptv-org/database) (public domain): `channels.json` and `logos.json` (about 14 MB, 30,000 open TV channels). `refresh()` downloads them and keeps a small index in `storage/app/channel-catalog.json` (about 4 MB). Peak memory about 140 MB (the job raises its own limit; the queue worker restarts after it, which is harmless).
- `fill()` looks at channels without a logo or without a `tvg-id`: their own tvg-id when it is an iptv-org id, else the name (`EpgService::nameKey`). A name used in several countries counts only when the channel's country (tvg-id, category, name tag or source name) picks exactly one. Results go in `auto_logo_url` / `auto_tvg_id`, which imports don't overwrite. The source's own logo and tvg-id always win (`Channel::logo()`, `Channel::guideId()`). The TV guide matches by `auto_tvg_id` too ([epg.md](epg.md)).
- Runs after every import (for that source, with the saved index), weekly through `iptv:channel-upkeep` (`FillChannelInfo`, downloads when the index is 7 days old), and with **Look up now** (`POST admin/playlist-options/fill-info`, downloads again). The page shows when the database was downloaded, how many logos and guide IDs were found, and the last download error.
- On the dev data: 739 logos and 220 guide IDs found.

## Catch-up (`CatchupService`)

Where a provider keeps an archive, viewers can watch programmes from the past days in apps that support it. NeboTV records nothing; it passes on the provider's archive.

- **Import:** the playlist's `catchup` (`xc`, `shift`, `append`, `default`, `flussonic`), `catchup-days` (also `tvg-rec` / `timeshift`) and `catchup-source` are stored (`channels.catchup`, `catchup_days`, `catchup_source`). Days without a style: an Xtream address means `xc`, else `shift`. No days given: 5.
- **To apps:** M3U entries get `catchup="default" catchup-days="N" catchup-source="{server}/catchup/{id}/{token}?utc={utc}&duration={duration}"`; the Xtream list gets `tv_archive = 1` and `tv_archive_duration`.
- **Playing:** `GET /catchup/{channel_id}/{token}?utc=&duration=` (M3U), `GET /timeshift/{username}/{password}/{minutes}/{Y-m-d:H-i}/{stream}.ts` and `GET /streaming/timeshift.php?…` (Xtream). Same checks, logging and limits as `/play` (403 when not allowed), then a redirect (or the proxy) to the provider's archive address: `xc` → `/timeshift/{user}/{pass}/{minutes}/{start}/{id}.ts` on the stream's server; `shift` → `?utc={start}&lutc={now}`; `append` / `default` → `catchup-source` with its placeholders filled in (`{utc}`, `${start}`, `{utcend}`, `{duration}`, `{duration:60}`, `{offset:1}`, `{Y}{m}{d}{H}{M}{S}`, `{utc:YmdHMS}`…, `CatchupService::fill`); `flussonic` → `archive-{start}-{duration}.m3u8` / `timeshift_abs-{start}.ts`. Outside the archive, or a channel without catch-up: `404`. Receiver channels have none.
