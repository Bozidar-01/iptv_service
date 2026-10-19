# TV guide (EPG)

TV apps show what's on now and next for every channel that has a guide. The guide comes from XMLTV files (admin → **TV guide**). Channels are matched to it automatically, and it reaches apps through both the playlist link and the Xtream login.

## Guide sources (`epg_sources`, `EpgImportService`)

- Admin → **TV guide** lists the guides with status, channel and programme counts, **Import now** and **Delete**, plus a form to add one (name + XMLTV URL, plain or `.gz`).
- **Default guides:** `DefaultEpgSourcesSeeder` adds free iptv-epg.org files for Macedonia, Italy, Serbia, Croatia, Bosnia and Herzegovina and Albania. `DatabaseSeeder` calls it while there are no guides. Run `php artisan db:seed --class=DefaultEpgSourcesSeeder --force` to add them to an existing install. The ids in those files follow iptv-org's `tvg-id`s.
- **Guides for the countries of your channels:** the TV guide page lists countries whose channels have no guide (read from playlist ids such as `CanalShowsport.ar@SD`, iptv-org style) with the number of channels, and adds the free iptv-epg.org file of one country, or of all of them, in one click (`EpgGuideCatalog`, `POST epg/countries`). 55 countries have a file (`EpgGuideCatalog::COUNTRIES`); a country is suggested from 3 channels. A guide for a country the channels use is what lifts coverage most: iptv-org ids match the files exactly.
- **Import** (`SyncEpgSource` job, `iptv:sync-epg [--now]`, daily at 03:30):
  - Downloads the file and reads it with `XMLReader`, so memory stays constant.
  - Stores guide channels (`epg_channels`: xmltv id, name, `name_key`, `alt_keys` = the keys of the other display names) and programmes (`epg_programmes`) from 6 hours ago to `EPG_DAYS` (3) days ahead.
  - **Keeps programmes only for guide channels one of our enabled channels can match** (by id, with or without `@SD`/`@HD`, by name key or any other display name, or the admin's own guide id): a 47 MB country file would otherwise fill the database with channels nobody has. The channel list of the guide stays complete. When there are no channels yet, everything is kept. After a channel source import, a guide that now has matched channels without programmes is imported again (`EpgService::refreshGuidesMissingProgrammes`, at most every 6 hours per guide); the nightly import catches the rest.
  - Skips filler titles ("No Data", "TBA", …).
  - Ids that differ only in case count once.
  - Replaces the guide's old data in one transaction. A failed import keeps the old guide and shows the error.
- After every guide import and every channel source import, channels are matched again.

## Matching (`EpgService::matchChannels`)

Each channel gets `epg_source_id` + `epg_id` (the guide's channel id):

1. Its `tvg-id` (or, when it has none, the iptv-org id found by name: `auto_tvg_id`, [playlist-options.md](playlist-options.md#logos-and-guide-ids-channelcatalogservice)) equals a guide channel id. iptv-org's `@SD` / `@HD` suffix is also tried without the suffix: `Kanal5.mk@SD` → `Kanal5.mk`.
2. Otherwise, the normalised names are equal (`EpgService::nameKey`). Normalising removes a country prefix (`MK - `), brackets (`(1080p)`, `[Geo-blocked]`), quality tags (HD, FHD, 4K…) and punctuation, transliterates Cyrillic and lower-cases. So `Канал 5`, `MK - Kanal 5` and `Kanal 5 HD` all become `kanal5`.
3. The guide channel's other display names count too (`alt_keys`).
4. When several guides have the channel, the guide of the channel's **own country** wins (from a playlist id like `Name.de@SD`; `uk` = `gb`; the guide's country comes from its file name `epg-de.xml.gz`), else the guide added first.

**By hand:** the channel form has a **Guide ID** field. When filled, it is kept (`epg_manual`) and never overwritten. When empty, matching is automatic, and the placeholder shows the current match. The form also shows **On now** (times in the app timezone, UTC).

Satellite receiver channels have a service reference as `tvg-id`, so they are matched by name (Rai 1 HD → `Rai1.it`). The receiver's own guide isn't used: Enigma2 only collects guide data for channels it has tuned, and it was empty on the Octagon.

## For TV apps

| Where | What |
|---|---|
| Playlist link `/playlist/{token}.m3u` | Header `#EXTM3U x-tvg-url="…/epg/{token}.xml" url-tvg="…"`. `tvg-id` is the guide id (`Channel::guideId()`: `epg_id`, else `tvg_id`, else `auto_tvg_id`). |
| `GET /epg/{token}.xml` (`epg`) | XMLTV for the user's channels, from 2 hours ago to `EPG_DAYS` ahead, streamed. `404` for unknown or disabled users. |
| `/m3u` and `/get.php` (username + password) | Header points to `/xmltv.php?username=…&password=…`. |
| `GET /xmltv.php?username=&password=` | Same XMLTV. An empty `<tv></tv>` for wrong credentials or inactive accounts. |
| `player_api.php` `get_live_streams` | `epg_channel_id` = guide id. |
| `player_api.php` `get_short_epg&stream_id=&limit=` | Next `limit` (default 4, max 50) programmes: `title` and `description` base64, `start`/`end`, `start_timestamp`/`stop_timestamp`. |
| `player_api.php` `get_simple_data_table&stream_id=` | Today onwards, with `now_playing` 1/0 and `has_archive` 0. |

## Coverage (2026-10-09, dev data)
- With the guides of the 45 countries the channels come from added (TV guide → *Add all*): 2,140 of 5,637 enabled channels have a guide (38%, was 11%) and 1,733 have programmes (31%). The rest are mostly satellite channels without a guide file for them (regional and local Spanish channels, radio, services) and iptv-org channels whose guide does not list them.

## Coverage (2026-10-07)
- 212 of 3,663 enabled channels matched a guide, and 151 of those had programmes.
- The Italian guide covers RAI, Mediaset and La7, including all RAI channels from the receiver.
- The free Macedonian guide currently contains only "No Data" entries, so Macedonian channels are matched but show nothing yet.
- Add more country files (`https://iptv-epg.org/files/epg-<country>.xml.gz`) for better coverage. The US file is 47 MB.
