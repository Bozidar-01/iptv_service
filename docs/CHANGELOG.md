# Changelog

Format: newest first. Each entry: date, short description, files touched. Add new entries under **Unreleased** as part of every change.

## Unreleased

### 2026-10-21 — Backup copies, viewing statistics, payments, the receiver's TV guide
- **Backup copies:** Find duplicates now keeps the other copies as hidden backups of the kept one by default (`channels.backup_for_id`): not listed or playable by themselves, still checked, and the first stand-ins when the kept one stops working. *Switch the others off* is still there. Channels: status *Backup copies*, badge *Backup copy*, bulk action *List again*.
- **Statistics:** most streams at once (and per day, from `iptv:sample-viewing` every 5 minutes into `viewing_samples`), plays / viewers / most at once per source, and the listed channels nobody played (Channels filter *Not watched in 30 days*).
- **Payments** (admins and resellers): prices (currency, admins' price per month, price per credit per reseller; a reseller's own price per month), a sale for every month sold or renewed (create, renew, edit, bulk extend, CSV import) with *Mark paid*, what each reseller owes for credits (charged at their price when given, minus payments an admin records). Money on the Statement and an *Owes* column on Sales.
- **Receiver TV guide:** relay **1.3** serves `/epg.xml` (the box's own guide as XMLTV); *Use the receiver's TV guide* on the Receiver page adds it as a guide source (the relay key is added only when downloading) and imports it nightly. Update the relay on the box for it (docs/features/receiver.md#updating-the-relay-on-the-receiver).
- Migration `2026_10_21_000001` (additive), checked up/down/up on MySQL. Tests: `PaymentTest` (7), `ReceiverGuideTest` (4), statistics (1 more), duplicates (1 more), relay (2 more). 835 PHP tests, 20 relay tests.
- Files: `app/Services/{ChannelDuplicateService,ChannelFailoverService,ChannelService,PlaylistOptionsService,StreamService,StatisticsService,SaleService,CreditService,UserBulkService,UserCsvService,ResellerStatementService,SalesReportService,EpgImportService}.php`, `app/Models/{Channel,User,CreditTransaction,EpgSource,Sale,ResellerPayment,ViewingSample}.php`, `app/Support/Money.php`, `app/Http/Controllers/Admin/{ChannelController,UserController,PaymentController,ReceiverController}.php`, `app/Console/Commands/SampleViewingCommand.php`, `relay/nebotv-relay.py`, `relay/test_relay.py`, views (channels, duplicates, edit, statistics, payments, statements, sales, receiver, layout), `routes/{web,console}.php`, `lang/mk.json`, `lang/mk/validation.php`, docs.

### 2026-10-20 — Channel health, ranked backups, faster lists, channel numbers, logos, quality names, catch-up
- **Closer look at HLS streams:** the video playlist inside a master playlist must list video (the next picture size is tried, as players do), the picture must move on between checks (`frozen`), and for channels people watch one piece of video is downloaded to see whether it arrives faster than it plays (`stream_speed`, *Slow* under 100%). New `stream_error` values `no-video` and `frozen`. The first look keeps 64 KB (was 4 KB, long master playlists were cut). Tried on real channels: only truly dead streams were flagged.
- **Reliability:** every check is counted per channel and day (`channel_check_days`); `channels.reliability` = % working over 7 days. Shown on the Channels list (also filter *Often not working*), the edit page (*How well it works*) and in Find duplicates, which now also prefers copies that are not slow and more reliable.
- **Viewers:** a device that asks for a channel 3 times in a minute is retrying; it counts as viewer trouble (filter *Viewers had trouble*), queues a quick check of the channel, and the device is sent to the next working copy for 10 minutes (other viewers keep the channel's own stream).
- **Ranked backups:** all copies with the same tvg-id plus the chosen backup, best first (chosen, not slow, reliable, picture). A slow channel moves to a faster copy and back when it is fast again; channels on a backup are checked every 15 minutes.
- **Faster lists:** M3U playlists and the full Xtream channel list come from ready files shared by viewers who see the same channels (`ListCacheService`), playlists, channel lists and guides are gzip-compressed for apps that accept it, and a right app password is remembered for 10 minutes instead of running bcrypt on every request.
- **One device, one slot:** each playlist download puts its own device key in the play links (`{token}_{key}`), so two identical TVs behind one router count as two devices; an app that loads its playlist again takes over its old slot. Xtream apps are still told apart by IP + app.
- **Playlist options** page: fixed **channel numbers** (`tvg-chno` / Xtream `num`; all channels numbered by the migration, new ones get the next numbers, *Number all channels again*), **quality in channel names** ("Rai 1 FHD", off by default), **list only the better copy** (off by default), **logos and guide IDs** from the iptv-org database for channels without them (weekly and after imports; also used by the TV guide match), and the number of channels with **catch-up**.
- **Catch-up** passed on from providers whose playlist has `catchup` / `catchup-days` / `tvg-rec`: M3U `catchup-source` to `/catchup/{id}/{token}`, Xtream `tv_archive` and `/timeshift/…` / `timeshift.php`, with the same checks and limits as `/play`, then a redirect to the provider's archive (xc, shift, append, default, flussonic).
- Daily `iptv:channel-upkeep` (04:45): numbers new channels, works out better copies, deletes old check history and unused lists, refreshes iptv-org data weekly. Optional `LIST_CACHE_DIR`; no `.env` change needed.
- Migration `2026_10_20_000001` (additive: channel columns, `channel_check_days`, `app_settings`, `active_sessions.device_key`; numbers existing channels). Checked up/down/up on MySQL. PHPStan baseline down from 8 to 2 findings.
- Tests: `ChannelHealthTest` (20), `PlaylistSpeedTest` (13), `ChannelInfoTest` (12); existing stream check fakes now return real live playlists. 822 PHP tests.
- Files: `app/Services/StreamCheckService.php`, `ChannelHealthService.php`, `ChannelFailoverService.php`, `StreamService.php`, `TokenService.php`, `ListCacheService.php`, `XtreamService.php`, `PlaylistOptionsService.php`, `ChannelNumberService.php`, `ChannelCatalogService.php`, `CatchupService.php`, `M3uParser.php`, `SourceSyncService.php`, `EpgService.php`, `ChannelDuplicateService.php`, `ChannelService.php`, `app/Support/GzipOutput.php`, `app/Models/Channel.php`, `app/Models/AppSetting.php`, `app/Http/Controllers/{PlayController,M3uController,XtreamController,CatchupController}.php`, `app/Http/Controllers/Admin/{ChannelController,PlaylistOptionsController}.php`, `app/Jobs/FillChannelInfo.php`, `app/Console/Commands/ChannelUpkeepCommand.php`, `routes/{web,player,console}.php`, `config/iptv.php`, views (channels list, edit, form, duplicates, playlist options, layout), `lang/mk.json`, `lang/mk/validation.php`, `tests/TestCase.php`, docs.

### 2026-10-19 — Statement: credits as a ledger
- The credits part of the statement is its own card, laid out as a ledger: opening balance, + given, − taken back, − used for new subscriptions, − used for renewals, and the closing balance highlighted at the bottom (it used to be a cramped list with wrapping labels). The opening balance is 0 for a reseller's first month. Test added for the ledger and the page markup.
- Files: `resources/views/admin/statements/index.blade.php`, `public/css/admin.css`, `app/Services/ResellerStatementService.php`, `tests/Feature/StatementTest.php`, `lang/mk.json`.

### 2026-10-19 — Measured stream quality, reseller statements, viewers' favourites
- **Stream quality:** while it checks channels, the stream check reads the best picture (`RESOLUTION`, `BANDWIDTH`) of an HLS master playlist and saves it (`channels.quality_height` / `quality_kbps`, additive migration). **Find duplicates** now suggests the copy that measures best (a "News HD" that is really 480p loses to a plain "News" that is 1080p) and shows a *Quality* column. Only HLS master playlists are measured; others fall back to the name tag.
- **Statement** (menu; reseller: own, admin: any reseller, also linked from Sales): a reseller's month: opening and closing balance, credits given / taken back / used for new subscriptions and renewals, new subscriptions and renewals, users added, ended and active, the month's credit history; CSV download and Print.
- **Favourites:** viewers choose favourites on their personal page (search, add, move, remove, up to 100). They come first in the M3U playlist (group *Favourites*) and as the first category *Favourites* in Xtream apps. New table `user_favorites` (additive migration).
- Tests: `StreamQualityTest` (5), `StatementTest` (9), `FavoritesTest` (11).
- Files: `app/Services/StreamCheckService.php`, `app/Services/ChannelDuplicateService.php`, `app/Services/ResellerStatementService.php`, `app/Http/Controllers/Admin/StatementController.php`, `app/Services/FavoriteService.php`, `app/Services/TokenService.php`, `app/Services/XtreamService.php`, `app/Http/Controllers/XtreamController.php`, `app/Http/Controllers/SelfServiceController.php`, views, migrations `2026_10_19_000001/2`, `routes/web.php`, `routes/player.php`, `public/css/admin.css`, `lang/mk.json`, docs.

### Docs
- The changelog file was emptied by mistake in the previous change (#47) and is restored here from the version before it.

### 2026-10-10 — Organize channels by country
- **Cleanup rules → Organize by country:** every channel goes in a category named after its country, with *Radio*, *Adult* and *International* apart, and big countries split by genre ("Italy – News"). The country is read from the playlist id, group, name tag, source list name, TV guide country and keywords. Preview first, then save and apply; with *Keep it on*, every import follows it. Excluding sources is possible; repeatable and fast (0.5 s for 12,000 channels).
- Tried on a copy of the production database: 457 categories became 183 (142 country groups among the 5,120 enabled channels).
- No schema change (one `cleanup_rules` row of kind `country`). Tests: `CountryOrganizeTest` (44). 
- Files: `app/Support/Countries.php`, `app/Services/CountryOrganizeService.php`, `app/Http/Controllers/Admin/CleanupRuleController.php`, `app/Services/SourceSyncService.php`, `resources/views/admin/cleanup/index.blade.php`, `routes/web.php`, `lang/mk.json`, docs.

### 2026-10-10 — Refused plays with the reason, receiver page, tidy the receiver's list
- **Activity → Refused:** every refused play with the exact reason and what to do (disabled, expired, device disconnected, channel off, hidden, not in bouquet, too many devices), filters, and the latest five on the user page. The app still gets the same 403. New table `denied_plays` (additive migration), `StreamService::denialReason` / `authorize`.
- **Receiver page** (Sources → Receiver): online, relay version, tuners in use, the satellite it is on now, one-cable or not, and the relay's latest refusals and no-video streams with channel names. Relay **1.2** reports them in `/status` (copy `relay/nebotv-relay.py` and restart).
- **Shorten the receiver's list:** switch off radio/data services, internal stream entries and/or channels without a guide, with counts (nothing deleted). On the dev data radio + internal would switch off 1,051 of 2,001 channels; try one internal entry first, they play only if the box runs that service.
- Tests: `DeniedPlayTest` (11), `ReceiverStatusTest` (10), 2 more relay tests (19). 709 PHP tests.
- Files: `app/Services/StreamService.php`, `app/Models/DeniedPlay.php`, `app/Http/Controllers/Admin/LogController.php`, `app/Services/ReceiverStatusService.php`, `app/Services/ReceiverTidyService.php`, `app/Http/Controllers/Admin/ReceiverController.php`, `relay/nebotv-relay.py`, views, routes, `lang/mk.json`, docs.

### 2026-10-10 — Receiver relay 1.1: channels on one transponder share a tuner
- The relay counted **different channels** against the number of tuners, so a third channel was refused even when it was on the same frequency as one already playing. It now counts **transponders** (read from the service reference): any channels on the same transponder share one tuner. On the Octagon source that is 2,001 channels on 306 transponders, about 6.5 each.
- New setting **`single_cable = yes`** for two tuners on one dish cable: the receiver can be on one satellite at a time, so a channel from another satellite is refused **at once** (`503 The receiver is on another satellite right now`, `Retry-After`) instead of the player waiting about 6 seconds and showing nothing.
- The relay logs `No video from the receiver for <ref>` when the receiver accepts a stream but sends nothing, and `/status` now reports `version`, `active_tuners`, `single_cable` and `satellites`.
- Update on the receiver: copy `relay/nebotv-relay.py` and restart (docs/features/receiver.md#updating-the-relay-on-the-receiver). The panel needs no change.
- Tests: 17 relay tests (was 9), repeated 15 times under full CPU load without a failure.
- Files: `relay/nebotv-relay.py`, `relay/test_relay.py`, `relay/nebotv-relay.conf.example`, `docs/features/receiver.md`.

### 2026-10-09 — Fixed logo, language and account; only the menu scrolls
- The sidebar has three zones: the **logo** on top and the **language, light/dark mode and account** at the bottom stay in place; only the **menu** between them scrolls (it got long with the new pages, and used to push the language and account out of view). The bell bar at the top of the page also stays in view while the page scrolls. On a phone the opened menu behaves the same.
- Tests: three browser tests (`e2e/layout.spec.js`: logo, language/account and top bar stay put on a short window; the phone menu). They fail on the old CSS.
- Files: `public/css/admin.css`, `e2e/layout.spec.js`.

### 2026-10-09 — Backup check and automatic housekeeping of users
- **Backups** page (Account menu, admins only): the backups on every disk and **Check the newest backup**: it reads the file to its end, compares its tables with the database, looks for the users, and that the off-site disk has the same newest file. A pass is remembered and ticks *A restore has been tested once* on the launch checklist. Not a restore, but it catches empty, cut-off and unreadable backups.
- **Daily housekeeping** (`iptv:cleanup-users`, 05:00): trials are deleted `TRIAL_KEEP_DAYS` (7) days after they ended; active subscribers whose subscription ended more than `EXPIRED_DISABLE_DAYS` (60) days ago are switched off. Audit-logged as "system". **Renew now also turns a switched-off user back on** (single, from an alert, and bulk).
- No schema change. Tests: `UserCleanupTest` (7), `BackupCheckTest` (11). 687 PHP tests.
- Files: `app/Services/BackupService.php`, `app/Services/UserCleanupService.php`, `app/Console/Commands/CleanupUsersCommand.php`, `app/Http/Controllers/Admin/BackupController.php`, `resources/views/admin/backups/index.blade.php`, `app/Services/UserService.php`, `app/Services/UserBulkService.php`, `app/Services/LaunchChecklistService.php`, `app/Models/AuditLog.php`, `config/iptv.php`, `routes/web.php`, `routes/console.php`, `.env.example`, `lang/mk.json`, docs.

### 2026-10-09 — Live view and account-sharing alerts
- **Live** page (Subscribers menu, admins and resellers): who is watching right now (user, channel, IP, player, since) with Disconnect, how many devices and users, the most watched channel; refreshes every 20 seconds. A reseller sees their own users.
- **Sharing alerts:** hourly check (`iptv:detect-sharing`); a login used from `SHARING_IPS` (3) or more different IPs within `SHARING_HOURS` (24) raises an alert once a day, for the admin, the user's reseller and the email digest. The user page has *Where this user watches from* (addresses of the last 7 days, plays, last use).
- No schema change. Tests: `SharingAndLiveTest` (9). 670 PHP tests.
- Files: `app/Services/SharingDetectionService.php`, `app/Console/Commands/DetectSharingCommand.php`, `app/Http/Controllers/Admin/LiveController.php`, `resources/views/admin/live/index.blade.php`, `app/Models/StreamAlert.php`, `app/Mail/StreamAlertsMail.php`, `app/Http/Controllers/Admin/UserController.php`, `resources/views/admin/users/show.blade.php`, `config/iptv.php`, `routes/web.php`, `routes/console.php`, `.env.example`, `lang/mk.json`, docs.

### 2026-10-09 — Sales report
- New admin page **Sales** (Subscribers menu, admins only): months sold (credits spent), credits given, new users, users expiring in 30 days and users that expired in the last 30 days, a 12-month column chart, and a per-reseller table with the balance and *about N days left* at the recent pace (yellow under 14 days). Includes the admins' own users.
- No schema change. Tests: `SalesReportTest` (8). 661 PHP tests.
- Files: `app/Services/SalesReportService.php`, `app/Http/Controllers/Admin/SalesReportController.php`, `resources/views/admin/sales/index.blade.php`, `resources/views/layouts/admin.blade.php`, `routes/web.php`, `lang/mk.json`, docs.

### 2026-10-09 — CSV import and export of users
- **Export** (users page): the users the list filters match as CSV for accounting, with bouquets; passwords only with `?passwords=1` (audit-logged). Spreadsheet formulas in cells are defused.
- **Import** (users page): up to 2,000 users from a CSV (username, password, expiration_date, max_connections, bouquets, email, status), checked like the user form; bad rows are listed and skipped, the rest created; empty passwords are made up and shown in a copyable list. For a reseller: own users, bouquets they hold, stream cap, user limit and credits. *Only check the file* is ticked by default and creates nothing.
- No schema change. Tests: `UserCsvTest` (14). 653 PHP tests.
- Files: `app/Services/UserCsvService.php`, `app/Http/Controllers/Admin/UserCsvController.php`, `resources/views/admin/users/import.blade.php`, `resources/views/admin/users/index.blade.php`, `app/Models/AuditLog.php`, `routes/web.php`, `lang/mk.json`, docs.

### 2026-10-09 — Fix: flaky receiver relay test
- `test_limits_channels_to_the_tuners` waited a fixed 0.2 s for the first stream to take the tuner and assumed the stream was still open afterwards, so it failed on a slow CI machine (PR checks, not the relay itself). The fake receiver now keeps the first stream open until the test releases it, and the test waits until the relay reports the tuner taken. Passes 15 of 15 runs with all CPU cores busy.
- Files: `relay/test_relay.py`.

### 2026-10-09 — Bulk actions on users
- The users list has checkboxes and a bar for **Enable**, **Disable**, **Renew** (1 / 3 / 6 / 12 months), **Add a bouquet**, **Remove a bouquet** and **Delete**, for the selected users or *all N matching* the list filters. Admins and resellers (own users only; a reseller only uses bouquets they hold).
- Only subscribers are changed. A reseller's renewal costs 1 credit per month per user, all or nothing; users who never expire are skipped. Set-based queries (checked on MySQL), written to the audit log.
- No schema change. Tests: `UserBulkTest` (13). 639 PHP tests.
- Files: `app/Services/UserBulkService.php`, `app/Services/UserService.php` (`filteredQuery`), `app/Http/Controllers/Admin/UserController.php`, `resources/views/admin/users/index.blade.php`, `app/Models/AuditLog.php`, `routes/web.php`, `lang/mk.json`, docs.

### 2026-10-09 — Cleanup rules for channel names and categories
- New admin page **Cleanup rules** (Content menu): rules that tidy names and categories on every import and on demand (*Apply now*): change channel names (contains / exactly / regex), rename or merge categories, and put channels in a category by name (optionally only for channels in one category). Per source or for all, with *Preview* before saving and ready-made sets: remove `[301]` tags, radio, satellite group names, genres for the *Satellite* group (Sports, News, Kids, Movies, Music, Documentary).
- The import applies the rules before saving but keeps the channel's identity key from the raw playlist, so rules never create duplicates or lose bouquets. Categories left empty by *Apply now* are deleted. Audit-logged.
- Measured on the dev data: all four sets would change 570 names and 283 categories.
- New table `cleanup_rules` (additive migration). Tests: `CleanupRuleTest` (15). 626 PHP tests.
- Files: `app/Services/CleanupRuleService.php`, `app/Services/CleanupRules.php`, `app/Models/CleanupRule.php`, `app/Http/Controllers/Admin/CleanupRuleController.php`, `app/Services/SourceSyncService.php`, `resources/views/admin/cleanup/index.blade.php`, `database/migrations/2026_10_09_000013_create_cleanup_rules_table.php`, `routes/web.php`, `lang/mk.json`, docs.

### 2026-10-09 — TV guide for many more channels
- **Guides for the countries of your channels:** the TV guide page lists countries that have channels without a guide (from ids like `CanalShowsport.ar@SD`) and adds the free iptv-epg.org guide of one country, or all of them, in one click. 55 countries are known.
- **The importer keeps only programmes of guide channels some channel of ours can match** (by id, name or any other display name), so big country files (US 47 MB) don't fill the database; the guide's channel list stays complete. After a channel source import, guides that now have matched channels without programmes are imported again (at most every 6 hours).
- **Better matching:** a guide channel's other display names count too, and when several guides have a channel name the guide of the channel's own country wins (`.de` → Germany, `.uk` → United Kingdom), else the oldest.
- **Measured on the dev data** (5,637 enabled channels, all 45 suggested country guides added): channels with a guide 635 → 2,140 (11% → 38%), with programmes now 421 → 1,733 (7.5% → 31%); 362,000 programmes and 24,900 guide channels stored.
- New column `epg_channels.alt_keys` (additive migration). Tests: `EpgCoverageTest` (12). 611 PHP tests.
- Files: `app/Services/EpgGuideCatalog.php`, `app/Services/EpgService.php`, `app/Services/EpgImportService.php`, `app/Services/SourceSyncService.php`, `app/Models/EpgSource.php`, `app/Models/EpgChannel.php`, `app/Http/Controllers/Admin/EpgSourceController.php`, `resources/views/admin/epg/index.blade.php`, `database/migrations/2026_10_09_000012_add_alt_keys_to_epg_channels.php`, `routes/web.php`, `lang/mk.json`, docs.

### 2026-10-09 — Fix: "Your page" link for admins and resellers
- The *Message for the user* and the *Page for this user* box offered the subscriber page link for admins and resellers too, where it answers 404 (only subscribers have a page). Both now leave it out for them.
- Test: added to `LoginMessageTest`.
- Files: `app/Services/UserService.php`, `resources/views/admin/users/show.blade.php`, `tests/Feature/LoginMessageTest.php`.

### 2026-10-09 — Fix: Xtream login in IPTV Smarters (port in `server_info`)
- For an https site the Xtream login answered `server_protocol: https` together with `port: 80`. Apps that build their next requests from those fields (IPTV Smarters) can then go to `https://host:80` and the playlist is not added, although the login itself returns `auth: 1` and the M3U link works. `server_info.port` is now the port the request came in on (443 on https); `https_port` as before.
- Test: the ports and protocol for https, http and http on another port.
- Files: `app/Services/XtreamService.php`, `tests/Feature/XtreamTest.php`, `docs/features/playlist-and-streaming.md`.

### 2026-10-09 — Find duplicate channels
- New **Find duplicates** page (Channels → button, admins only): groups enabled channels that are the same one (names equal after ignoring case, country prefix, quality tags and punctuation, or the same guide id), suggests the copy that works, then best quality, then not a satellite receiver, then most bouquets, and switches the others off. Choose per group, per page, or *keep the suggested copy in all groups* (limited by the search).
- Nothing is deleted: the extra copies are disabled (a sync keeps them disabled) and the kept channel is added to their bouquets first. Groups nobody gets (not in a bouquet) and groups over 12 copies are left out. Written to the audit log.
- No schema change. Tested on the dev data (5,637 channels, 619 groups in 0.13 s).
- Tests: `DuplicateChannelTest` (36). 597 PHP tests.
- Files: `app/Services/ChannelDuplicateService.php`, `app/Http/Controllers/Admin/ChannelController.php`, `resources/views/admin/channels/duplicates.blade.php`, `resources/views/admin/channels/index.blade.php`, `app/Models/AuditLog.php`, `routes/web.php`, `lang/mk.json`, docs.

### 2026-10-09 — Renew button (admins and resellers)
- **Renew** on the user page: choose 1 / 3 / 6 / 12 months and the end date moves later from the current end date (or from now when it already ended). Admins renew for free; a reseller with credits pays 1 credit per month (a reseller without credits pays nothing; not enough credits refuses it and changes nothing). A trial becomes a normal user; the renewal is in the audit log.
- Alerts about a subscription (expiring, expired, *asks to renew*) get a **Renew 1 month** button on the Alerts page, and are marked read once the user is renewed.
- No schema change.
- Tests: `RenewTest` (17), a browser test. 561 PHP tests.
- Files: `app/Http/Controllers/Admin/UserController.php` (`renew`), `app/Services/UserService.php` (`renewedUntil`, `renew`), `resources/views/admin/partials/renew-form.blade.php`, `resources/views/admin/partials/alert-item.blade.php`, `resources/views/admin/users/show.blade.php`, `routes/web.php`, `lang/mk.json`, docs.

### 2026-10-09 — Audit log
- New admin page **Audit log** (Account menu, admins only): who changed what, with filters (person, kind of change, name). Records user created / changed (what changed, never the password), deleted, enabled / disabled, trials, new playlist links, disconnected devices, credits given / taken / unlimited, channels hidden / shown, and deleted sources, bouquets and channels. Kept for a year.
- Entries are written by `AuditService` after the change succeeded; a failed write is reported but never blocks the change. Nothing is recorded when a save changes nothing.
- New table `audit_logs` (additive migration); pruned with `model:prune`.
- Tests: `AuditLogTest` (12). 544 PHP tests.
- Files: `app/Services/AuditService.php`, `app/Models/AuditLog.php`, `app/Http/Controllers/Admin/AuditLogController.php`, the user / trial / credit / hide / source / bouquet / channel controllers, `database/migrations/2026_10_09_000011_create_audit_logs_table.php`, `resources/views/admin/audit/index.blade.php`, `routes/web.php`, `routes/console.php`, `lang/mk.json`, docs.

### 2026-10-09 — Copy login message, trial accounts, hide a whole category
- **Message for the user** on the user page (admins and resellers): server URL, username, password (when stored), playlist link, the user's own page and the end date as one text with a *Copy message* button, in the user's language. Paste it into WhatsApp / Viber.
- **New trial** (Users page): a 24 hour / 3 day / 7 day test account with a random login and one device, free of credits. A reseller can make `TRIALS_PER_DAY` (default 5) per 24 hours and trials count against their user limit; admins are not limited. *Trial* badge in the list and user page, `is_trial` for Xtream apps; it stops being a trial when the end date is moved later. New column `users.is_trial` (additive migration).
- **Hide a whole category:** a *By category* panel on the Hide channels pages with *Hide all* / *Show all* per category.
- Tests: `TrialTest` (9), `LoginMessageTest` (4), three category tests; a browser test for trials. 532 PHP tests.
- Files: `app/Http/Controllers/Admin/TrialController.php`, `app/Services/UserService.php` (`createTrial`, `recentTrials`, `loginMessage`), `app/Services/ChannelHideService.php` (`categoryStats`), `app/Http/Controllers/Admin/HiddenChannelController.php`, `database/migrations/2026_10_09_000010_add_is_trial_to_users.php`, views (`users/trial`, `users/show`, `users/index`, `hidden-channels/index`, `partials/user-status`), `config/iptv.php`, `.env.example`, `routes/web.php`, `lang/mk.json`, docs.

### 2026-10-09 — Hide channels (per user, and for all of a reseller's or the admins' users)
- **New page *Hide channels*** (menu, admins and resellers): the channels of the bouquets the person holds, with search, category, bouquet and shown/hidden filters, and bulk *Hide for all my users* / *Show again* (selected rows, or everything matching the filters). A reseller sees only their own bouquets' channels; an admin's list covers the admins' own users (those without a reseller).
- **Per user:** *Choose channels* on the user page opens the user's channels and hides some for that user alone; each row says why it is hidden.
- Bouquets are never changed, so other users and resellers are unaffected. Hidden channels disappear from the playlist, the Xtream API, the guide and playback (403), and from the channel count.
- Two new tables (`user_hidden_channels`, `owner_hidden_channels`); additive migration. Nothing is hidden by default.
- `Channel::scopeAvailableTo` now applies the hide lists; `StreamService::isAllowed` and `UserService::channelCount` use it (one rule everywhere).
- Tests: `HiddenChannelTest` (17: what a user gets, playlist/Xtream/playback/count, pages, reseller isolation, bulk, filters, cascade) and a browser test; migration up, down, up and the queries checked on MySQL.
- Files: `app/Services/ChannelHideService.php`, `app/Http/Controllers/Admin/HiddenChannelController.php`, `app/Models/Channel.php`, `app/Models/User.php`, `app/Services/StreamService.php`, `app/Services/UserService.php`, `database/migrations/2026_10_09_000009_create_hidden_channels_tables.php`, `resources/views/admin/hidden-channels/index.blade.php`, `resources/views/admin/users/show.blade.php`, `resources/views/layouts/admin.blade.php`, `routes/web.php`, `lang/mk.json`, `docs/features/hide-channels.md`, docs, `tests/Feature/HiddenChannelTest.php`, `e2e/hide-channels.spec.js`.

### 2026-10-09 — Launch checklist page
- New admin page **Launch checklist** (Account menu): what to have in place before real viewers and resellers use the panel, in four groups (security, backups, background jobs, content). Most items are checked live by the app (2FA on, no sample password, production mode, https, last backup and off-site copy, scheduler and queue, mail, a source/bouquet/user exist); items the server can't see (APP_KEY saved, restore tested, secrets replaced, outside monitor, tried on a TV, tried as a reseller) are ticked by hand. Shows *N of M done*; Sentry is optional and not counted.
- New table `checklist_ticks` (additive migration). Admins only: resellers get 403 and no menu entry.
- Tests: `LaunchChecklistTest` (access, menu, every automatic check, ticking, unknown keys, progress).
- Files: `app/Services/LaunchChecklistService.php`, `app/Http/Controllers/Admin/LaunchChecklistController.php`, `app/Models/ChecklistTick.php`, `database/migrations/2026_10_09_000008_create_checklist_ticks_table.php`, `resources/views/admin/launch-checklist/index.blade.php`, `routes/web.php`, `resources/views/layouts/admin.blade.php`, `lang/mk.json`, docs.

### 2026-10-09 — Faster MySQL check
- The MySQL job's database container keeps its data in memory (`--tmpfs`), does not flush to disk on every commit, and is polled every 2 s instead of 5 s. The data is thrown away after the run anyway.
- Files: `.github/workflows/ci.yml`, `docs/deployment.md`.

### 2026-10-09 — Faster pull request checks
- **Browser tests run in two halves** at the same time (Playwright `--shard`, one runner each). The required check is still called `Browser tests`: it is a small job that passes only when both halves pass, so branch protection needed no change.
- **`vendor/` is cached** (key: `composer.lock`) in the style/PHPStan, browser and MySQL jobs too, as it already was in the PHP tests job.
- Files: `.github/workflows/ci.yml`, `docs/deployment.md`.

### 2026-10-09 — Recommendations for faster checks
- `docs/deployment.md` (*Automated checks*) lists what would still make pull request checks faster, ranked by benefit: shard the browser tests, cache composer, speed up the MySQL job, skip browser tests for docs-only changes, larger runners. Docs only.
- Files: `docs/deployment.md`.

### 2026-10-09 — Faster pull requests and merges
- **Checks run once.** They run on the pull request (and on `staging`), no longer a second time after the merge to `main`: `main` only receives pull requests that passed on exactly this code (branch protection: required checks + branch up to date), so the second run repeated the same tests. The deploy no longer waits for them: merge → live in about 30 seconds instead of about 2 minutes.
- **Auto-merge:** the repository now allows auto-merge and deletes merged branches by itself. `gh pr merge --auto --squash` merges the moment the five checks are green, with no polling; deleting the branch no longer closes pull requests that were stacked on it.
- The `ship-to-main` skill follows this and says how to waive the go-ahead question for one conversation.
- Timing before: PR checks about 90 s (browser tests 80 s, MySQL 65 s, PHP tests 41 s, in parallel), then again about 85 s on `main`, then deploy 27 s.
- Files: `.github/workflows/ci.yml`, `.claude/skills/ship-to-main/SKILL.md`, `docs/deployment.md`.

### 2026-10-09 — Show and copy the password on the user page
- The **Log in with username and password** card on the user page now has the password too: hidden by default, *Show current password* reveals it, *Copy* copies it (also when hidden), *Change password* below. Same encrypted copy as the edit page; users saved before it was added show "not stored" until their password is set again. A reseller sees it only for their own users.
- Tests: three more in `PasswordRevealTest`, one browser test (show, hide, copy). 488 tests.
- Files: `resources/views/admin/users/show.blade.php`, `docs/features/admin-panel.md`, `tests/Feature/PasswordRevealTest.php`, `e2e/users.spec.js`.

### 2026-10-09 — Fix: Credits page showed "Unlimited" for resellers that have credits
- On the Credits page for **all resellers** every card said *Unlimited*, though the balance was saved (the history showed it). The page loaded each reseller with only `id`, `username` and `credits`, and "limited by credits" also needs the role, so every reseller looked like an account without a limit. It now loads the role too. Only the display was wrong: balances, charging and the history were correct.
- Test: the all-resellers page shows each reseller's real balance (fails on the old code).
- Files: `app/Http/Controllers/Admin/CreditController.php`, `tests/Feature/CreditTest.php`.

### 2026-10-09 — Reseller credits
- Admins give **credits** to resellers; a reseller with credits can only give as many subscriptions as they can pay for. **1 credit = 1 month** of subscription for one user: creating a user or moving their end date later costs the whole months added (from the day the user's subscription runs out, or from today when it already ended), shortening or keeping it costs nothing. One extra day is not charged (a reseller's date can differ from the server's by a day: time zones) but any longer subscription costs at least 1 credit.
- A reseller **without** a balance (the default for existing resellers) is not limited, as before. Giving credits switches the limit on; *Make unlimited* switches it off. A reseller with credits must give an end date (no "Never"); the form shows the price while typing and a refused save changes nothing (user and credits are saved together under a lock on the reseller's row).
- New **Credits** page (reseller: own balance and history; admin: everyone's or one reseller's), a credits panel on a reseller's page (give / take back with a note, never below 0), *Starting credits* when creating a reseller. Every change is kept in `credit_transactions` (who, what, for which user, balance after).
- Fix found by the browser test: the end-date shortcuts (+1 month …) built the date in UTC, which could be a day off for a browser west of UTC; they now use the browser's own date and the same month rule as the server.
- Schema: `users.credits` (nullable), `credit_transactions`. Tests: `CreditTest`, `e2e/credits.spec.js`.
- Files: `app/Support/Credits.php`, `app/Services/CreditService.php`, `app/Models/CreditTransaction.php`, `app/Http/Controllers/Admin/{CreditController,UserController}.php`, `app/Exceptions/*`, `database/migrations/2026_10_09_000006_*`, `resources/views/admin/{credits/index,users/_form,users/show,users/index,dashboard-reseller}.blade.php`, `resources/views/layouts/admin.blade.php`, `routes/web.php`, `lang/mk.json`, `docs/**`.

### 2026-10-09 — Show a user's password on the edit page
- **Edit user** has a *Show current password* button (for the Xtream Codes login). Passwords were only stored as hashes, so a new `users.password_plain` column keeps an encrypted copy (`encrypted` cast, hidden from serialisation), written whenever a password is set through `UserService`. Users saved before this have none: the page says so; setting a new password fills it in. Resellers see it only for their own users (existing access rule). Anyone with the database and `APP_KEY` can read these passwords.
- Files: migration `…000007_add_password_plain_to_users`, `User`, `UserService`, `admin/users/_form`, `layouts/admin` (reveal button script), `lang/mk.json`.

### 2026-10-09 — Fix: Statistics page failed with a 500 on the second visit
- **Cause:** the report was cached with a date object (`Carbon`) inside. The real (database) cache refuses to rebuild objects (`cache.serializable_classes` is `false`), so the second request got a `__PHP_Incomplete_Class` and the page returned 500, for the 5 minutes the entry lived, in both languages (switching language reloads the page, which is how it showed). The first visit worked because nothing was cached yet, and the tests didn't catch it because the test cache keeps objects as they are.
- **Fix:** only plain values are cached (`from` is a date string); the cache key is versioned (`statistics:v2:`) so entries written by the old code are never read.
- Test: reads the report back through the database cache and loads the page repeatedly in both languages (fails on the old code). Checked nothing else caches an object. 
- Files: `app/Services/StatisticsService.php`, `resources/views/admin/statistics/index.blade.php`, `tests/Feature/Admin/StatisticsTest.php`.

### 2026-10-09 — Resellers
- New role **reseller**: signs in to the panel and sees and manages only their own users. Admin sets *Most users*, *Most streams per user* and the bouquets they may hand out. A reseller gets Dashboard, Users, Activity, Alerts (their users' only) and Security; the rest of the panel is 403. Details in `docs/features/admin-panel.md#resellers`.
- Access control: `User::scopeVisibleTo` + a scoped `{user}` route binding (someone else's user is a 404 on every route), `EnsureStaff` (admin or an active reseller) and `EnsureAdmin` (reseller → 403) run before the binding; limits and role/owner can't be set by a reseller whatever is posted.
- Schema: `users.role` is now a string(20) (was enum), `users.owner_id` (FK, null on delete), `users.max_customers`, `users.max_customer_connections`. Migration tried up / down / up on MySQL.
- Hardened after an independent review: the user limit is checked and the user created under a row lock (parallel creates can't exceed it), a reseller sees only the bouquets it holds on a user's page, array-shaped filters are ignored instead of failing, and rolling the migration back disables former resellers.
- Not included: credits or prices, reseller-specific emails (renewal requests show in their Alerts), per-reseller branding.
- Tests: `ResellerTest` (43 cases incl. 404 on every route for foreign users, limits, scoping of activity / alerts / sign-ins / dashboard). 438 tests.
- Files: `database/migrations/2026_10_09_000005_*`, `app/Models/User.php`, `app/Http/Middleware/{EnsureStaff,EnsureAdmin}.php`, `bootstrap/app.php`, `routes/web.php`, `app/Providers/AppServiceProvider.php`, `app/Http/Controllers/{Auth/AuthController,Admin/UserController,Admin/DashboardController,Admin/LogController,Admin/AlertController,Admin/SecurityController}.php`, `app/Services/UserService.php`, `resources/views/admin/{dashboard-reseller,users/_form,users/index,users/show}.blade.php`, `resources/views/layouts/admin.blade.php`, `lang/mk.json`, `docs/**`, `tests/Feature/ResellerTest.php`.

### 2026-10-09 — Expiry emails to subscribers
- Users get an optional **email** and **language of emails** (user form; shown on the user page). Subscribers with an address are emailed the expiry reminder (before the end date and when it has ended) with a button to their own page, where *Ask to renew* is, plus the `RENEWAL_CONTACT` line. Once per reminder, retried next run on failure (`stream_alerts.user_mailed_at`). `REMINDER_EMAIL_USERS=false` switches it off. Nobody is emailed until addresses are added.
- Schema: `users.email`, `users.locale`, `stream_alerts.user_mailed_at` (migration tried up/down/up on MySQL).
- `iptv:expiry-reminders` prints the number of emails sent.
- Tests: `UserReminderEmailTest` (13), 392 tests.
- Files: `database/migrations/2026_10_09_000004_*`, `app/Mail/SubscriptionReminderMail.php`, `resources/views/emails/subscription-reminder*.blade.php`, `app/Services/SubscriptionReminderService.php`, `app/Console/Commands/ExpiryRemindersCommand.php`, `app/Http/Controllers/Admin/UserController.php`, `app/Models/{User,StreamAlert}.php`, `resources/views/admin/users/{_form,show}.blade.php`, `config/iptv.php`, `.env.example`, `lang/mk.json`, `lang/mk/validation.php`, `docs/**`, `tests/Feature/UserReminderEmailTest.php`.

### 2026-10-09 — Subscriber page and smaller PHPStan baseline
- **`/me/{token}`**: a page a subscriber opens with their personal link: status, expiry, playlist link with copy button, setup steps, Xtream server + username (never the password), channel count, streams in use, and **Ask to renew** (alert for the admins, once a day). English or Macedonian (`?lang=` or the browser's language), no cookies, `noindex`, own request limit. The user page in the panel shows the link to send (*Page for this user*).
- New alert type `renewal_requested` (in the *Subscriptions* group of the email digest).
- PHPStan: date columns of `User`, `Source` and `EpgProgramme` are declared with `@property`, so the analyser knows they are dates: baseline 15 → 8 findings.
- Tests: `SelfServiceTest` (17), 379 tests. Looked at the page on a phone-sized screen in Macedonian.
- Files: `app/Http/Controllers/SelfServiceController.php`, `resources/views/me/show.blade.php`, `app/Models/{StreamAlert,User,Source,EpgProgramme}.php`, `routes/player.php`, `app/Providers/AppServiceProvider.php`, `public/css/admin.css`, `resources/views/admin/users/show.blade.php`, `lang/mk.json`, `phpstan-baseline.neon`, `docs/**`, `tests/Feature/SelfServiceTest.php`.

### 2026-10-09 — Usage statistics
- New **Statistics** page (sidebar, under Users): plays, viewers and channels watched for the last 7 / 30 / 90 days, plays per day and per hour as column charts, top 10 channels and top 10 users. `StatisticsService`: grouped queries only (a fixed number whatever the history size), 5-minute cache; MySQL and SQLite expressions for day and hour. Runs on the existing `stream_logs` data, no schema change.
- CSS: `.chart`, `.chart-axis`, `.bar` (theme tokens only; looked at in light and dark).
- Tests: `StatisticsTest` (9), 362 tests.
- Files: `app/Services/StatisticsService.php`, `app/Http/Controllers/Admin/StatisticsController.php`, `resources/views/admin/statistics/index.blade.php`, `resources/views/layouts/admin.blade.php`, `public/css/admin.css`, `routes/web.php`, `lang/mk.json`, `docs/**`, `tests/Feature/Admin/StatisticsTest.php`.

### 2026-10-09 — Disconnect a device
- The user page lists the **devices watching now** with **Disconnect** (and **Disconnect all**). It ends the device's session and blocks that device (IP + player) from starting a stream for 15 minutes (`StreamService::disconnect`, cache). Proxied streams stop (HLS parts refused; a running MPEG-TS stream stops at the next tick, `onTick` returning `false`); direct redirect streams can't be cut and end when the player reconnects.
- "Active now" on the user page counts only sessions from the last 5 minutes (`ActiveSession::active()`).
- Tests: `DisconnectDeviceTest` (7). 353 tests.
- Files: `app/Services/{StreamService,StreamProxyService,StreamPreviewService}.php`, `app/Models/ActiveSession.php`, `app/Http/Controllers/Admin/UserController.php`, `routes/web.php`, `resources/views/admin/users/show.blade.php`, `lang/mk.json`, `docs/**`, `tests/Feature/Admin/DisconnectDeviceTest.php`.

### 2026-10-09 — Ops: off-site backups, backup watch, uptime monitor, staging
- **Off-site backups:** `BACKUP_COPY_DISK` (e.g. `s3`) writes every backup to a second disk as well (`league/flysystem-aws-s3-v3`, works with AWS S3, Backblaze B2, Cloudflare R2, Wasabi via `AWS_ENDPOINT`). A failed copy fails the command and raises `backup_failed`; the local copy stays. Old backups are pruned on both disks.
- **Backup watch:** `backup_stale` alert (once a day) when no backup succeeded on every disk for 36 hours (`HealthService`, `BackupService::lastSuccess()`).
- **Uptime monitor:** `.github/workflows/uptime.yml` checks `/login` and `/health` every 30 minutes; a failed run emails you. Needs the repository variable `SITE_URL`.
- **Staging:** pushing a branch to `staging` runs the checks and updates a separate staging site (`deploy-staging` job, `STAGING_PATH` secret). `scripts/deploy.sh` takes a branch and `DEPLOY_RESET=1` (tested against a diverged clone). Setup in `docs/deployment.md#staging`.
- Not verified against a real bucket: the first `iptv:backup` after setting `BACKUP_COPY_DISK` shows it.
- Tests: off-site copy, copy failure, pruning on both disks, stale alert. 346 tests.
- Files: `app/Services/{BackupService,HealthService}.php`, `app/Models/StreamAlert.php`, `config/iptv.php`, `scripts/deploy.sh`, `.github/workflows/{ci,uptime}.yml`, `.env.example`, `lang/mk.json`, `composer.{json,lock}`, `.claude/skills/ship-to-main/SKILL.md`, `docs/**`, `tests/**`.

### 2026-10-09 — Dependency update and weekly audit
- `composer update`: Laravel 13.5 → 13.35, Guzzle 7 → **8**, psr7 2 → 3, Symfony 8.0 → 8.1, league/commonmark, flysystem, Pest, Pint and more. Fixes the 20+ advisories `composer audit` reported (Laravel email-rule CRLF injection and signed-URL path confusion, Guzzle host-check bypass, Symfony mime/mailer/routing/http-kernel, commonmark DoS). `composer audit` is clean.
- Checked after the update: all tests, Pint, PHPStan, and the real HTTP paths against a fake provider (playlist import, `php` and `nginx` stream proxy with redirects, HLS rewrite).
- New `audit.yml` workflow (weekly + when `composer.lock` changes) and `dependabot.yml` (weekly PRs for composer, npm, GitHub Actions).
- Files: `composer.lock`, `.github/workflows/audit.yml`, `.github/dependabot.yml`, `docs/deployment.md`.

### 2026-10-09 — Branch protection on main
- GitHub protects `main`: pull request + the five checks green and up to date, no direct or force pushes (also for the owner), linear history. Documented in `docs/deployment.md#branches-and-releases`. A required reviewer for the `production` environment is not available on this plan.
- Files: `docs/deployment.md`.

### 2026-10-09 — Branch workflow skills
- New project skills `start-work` (every task starts on a branch from `origin/main`, never on `main`) and `ship-to-main` (readiness checklist, pull request, CI, explicit go-ahead, merge = deploy, verify, how to go back). Rule added to `CLAUDE.md`; process in `docs/deployment.md#branches-and-releases`.
- Files: `.claude/skills/{start-work,ship-to-main}/SKILL.md`, `CLAUDE.md`, `docs/deployment.md`.

### 2026-10-09 — Automatic deploy
- New `deploy` job in `.github/workflows/ci.yml`: after every check passed on a push to `main`, SSH to the server and run `scripts/deploy.sh` (fast-forward to the tested commit, database dump, `composer install --no-dev`, migrate, caches, backup check, `/login` answers 200). One deploy at a time, `production` environment, does nothing without the `DEPLOY_*` secrets. Setup: [deployment.md](deployment.md#automatic-deploy).
- Tested end to end against a throw-away clone (fast-forward, migrations, caches, backup); stops on edited server files and on a failing step, printing how to go back.
- Files: `scripts/deploy.sh`, `.github/workflows/ci.yml`, `docs/deployment.md`.

### 2026-10-09 — Remaining review fixes
- **nginx mode:** an address without `m3u8` is first probed once (first bytes, cached an hour) so an HLS playlist served there is rewritten instead of leaking the provider address; protocol-relative redirects (`//host/x`) are followed.
- **php mode:** at most `PROXY_STREAMS_PER_DEVICE` (3) running streams per device (`429` + `Retry-After`).
- **Request limits** on `/m3u`, `/get.php`, `/xmltv.php` (player limiter) and `/player_api.php` (`THROTTLE_API_PER_IP`, 600/min per IP).
- Playlist: failures half way are reported; the play URL template uses a one-pass `strtr` with distinctive placeholders.
- Two-factor: an unfinished setup survives an `APP_KEY` change.
- Tests: 341 PHP. Files: `app/Services/{StreamProxyService,StreamPreviewService,TokenService,TwoFactorService}.php`, `app/Http/Controllers/M3uController.php`, `app/Providers/AppServiceProvider.php`, `routes/player.php`, `config/iptv.php`, `docker/nginx/stream-proxy.conf`, `.env.example`, `docs/**`, `tests/**`.

### 2026-10-09 — Fixes from the quality review
Three independent reviews (security, stream proxy, correctness) found these; all fixed:
- **Playlist:** channels that tie on category, position and name now have a fixed order (`channels.id` last), so chunked reading can't skip or repeat one. Prune models use `MassPrunable` (one `DELETE` per chunk) and the prune job can't overlap itself. The play log truncates very long user agents (was a 500 on MySQL).
- **Secrets:** Sentry no longer records provider calls (breadcrumbs / spans), cleans breadcrumbs, transactions, `/stream/…` addresses and `password=` / `token=` text. `sources.last_error` and `epg_sources.last_error` no longer keep a provider login (addresses are cut to the host). Backups fail loudly when the file can't be written (disks don't throw by default).
- **Sign-in:** limit per IP across usernames (20 a minute), subscribers guessing the panel login are counted, unknown usernames cost a password check, setting up two-factor needs the password, turning it on/off ends "Keep me signed in" logins, TOTP codes and recovery codes are claimed atomically. `TRUSTED_PROXIES` for load balancers.
- **Stream proxy:** refuses private / local addresses (`PublicAddress`, `PROXY_ALLOW_PRIVATE`), `HEAD` doesn't open the stream, any fetch error becomes a generic 502, part links are bound to the user and expire after 6 hours, `/live` has a request limit, nginx hides more provider headers and replaces provider errors.
- Tests: 333 PHP + 13 browser. `phpstan` / `pint` clean.
- Files: `app/Support/{PublicAddress,SentryScrubber}.php`, `app/Services/{StreamProxyService,StreamPreviewService,TwoFactorService,BackupService,SourceSyncService,EpgImportService,StreamService}.php`, `app/Http/Controllers/{PlayController,Auth/AuthController,Admin/SecurityController}.php`, `app/Models/{Channel,ActiveSession,LoginEvent,StreamLog}.php`, `bootstrap/app.php`, `config/{iptv,sentry}.php`, `routes/{player,console}.php`, `docker/nginx/*`, `resources/views/admin/security/index.blade.php`, `lang/mk.json`, `.env.example`, `docs/**`, `tests/**`.

### 2026-10-09 — Code quality: Pint, PHPStan, browser tests, more tests
- **Pint** works again (`composer reinstall laravel/pint --prefer-dist`: it had been installed from source, without the phar) with `pint.json` matching the house style. The whole code base was formatted once.
- **PHPStan + Larastan** level 5 (`phpstan.neon`); 15 analyser-limit findings in `phpstan-baseline.neon`. All model relations now have return types and generics. Real findings fixed: `AdminSeeder` read `env()` directly, which is `null` under `php artisan config:cache` (ADMIN_USERNAME / ADMIN_PASSWORD were ignored on a cached production config) → `config('iptv.admin_*')`; `ChannelService::bulkAction` throws on an unknown action instead of an `UnhandledMatchError`; one `str_replace` got ints.
- **Browser tests** (Playwright, 13): sign-in/out, wrong password, bulk selection and bulk actions, delete confirmations, theme and language switch, copy button, full two-factor flow. Own SQLite database, installed Chrome.
- **More PHP tests:** category CRUD (6), user on/off switch (3). 312 tests.
- **Removed** the unused Vite/Tailwind setup (`vite.config.js`, `resources/css|js`, npm packages); `composer setup` / `composer dev` no longer need npm.
- **CI** gained *Code style and static analysis* and *Browser tests* jobs. New scripts `composer lint | analyse | check`.
- Files: `pint.json`, `phpstan.neon`, `phpstan-baseline.neon`, `playwright.config.js`, `e2e/**`, `package.json`, `composer.json`, `.github/workflows/ci.yml`, `app/Models/*` (relation types), `config/iptv.php`, `database/seeders/AdminSeeder.php`, `app/Services/{ChannelService,TokenService}.php`, `tests/Feature/Admin/{CategoryTest,UserTest}.php`, `tests/Feature/AdminAccessTest.php`, `CLAUDE.md`, `docs/**`; formatting in ~47 files.

### 2026-10-09 — Hide provider address (stream proxy)
- **New source option *Hide provider address*** (`sources.proxy_streams`) with server setting `STREAM_PROXY` = `off` (default) / `php` / `nginx`. `/play` and Xtream `/live` then pass the stream on instead of redirecting to the provider, so viewers can't read the provider login. `StreamProxyService`; the existing preview proxy (`StreamPreviewService`) is reused.
  - `php`: works anywhere, holds a PHP worker per live viewer. `nginx`: PHP checks access, nginx passes the stream on (`X-Accel-Redirect`, `docker/nginx/stream-proxy.conf`: follows up to 3 redirects including relative ones, hides provider headers). Falls back to `php` when the nginx rules aren't there.
  - HLS: playlists rewritten, segments/keys through `GET /stream/{channel}/{token}?u=` with **encrypted** provider addresses and their own request limit (`THROTTLE_STREAM_PER_TOKEN`, 600).
  - Channel `http_user_agent` / `http_referrer` are sent to the provider (also fixes those channels in Xtream apps and the admin Watch player). Sessions stay alive while data flows (`StreamService::touch`, every 30 s in `php` mode, per segment for HLS).
  - Receivers are never proxied.
- Tested end to end in Docker with a fake provider (redirect chain incl. a relative redirect, headers, cookies, HLS).
- Schema: `sources.proxy_streams`. Tests: `StreamProxyTest` (17). 303 tests.
- Files: `app/Services/{StreamProxyService,StreamPreviewService,StreamService}.php`, `app/Http/Controllers/{PlayController,XtreamController,Admin/SourceController}.php`, `app/Models/Source.php`, `app/Providers/AppServiceProvider.php`, `routes/player.php`, `config/iptv.php`, `database/migrations/2026_10_09_000003_*`, `docker/nginx/{default,stream-proxy,stream-proxy-common}.conf`, `docker-compose.yml`, `resources/views/admin/sources/_form.blade.php`, `lang/mk.json`, `.env.example`, `CLAUDE.md`, `docs/**`, `tests/**`.

### 2026-10-09 — Two-factor sign-in, sign-in history, encrypted source addresses
- **Two-factor sign-in for admins** (optional): authenticator-app codes (`Totp`, RFC 6238) and 8 one-time recovery codes, set up on the new **Security** page with a QR code. Codes work once, wrong codes are limited, changing the setting asks for the password again. Secret encrypted, recovery codes hashed.
- **Sign-in history:** `login_events` records every panel sign-in attempt (IP, browser); last 30 on the Security page, pruned after 180 days.
- **`sources.url` is encrypted** (`encrypted` cast, column is now `text`; the migration encrypts existing rows and `down` decrypts them).
- Schema: `users.two_factor_*`, `login_events`, `sources.url` text. New dependency `bacon/bacon-qr-code`. New CSS tokens `--qr-bg` / `--qr-fg` (same in light and dark: QR codes need black on white).
- Tests: `TotpTest` (RFC test vectors), `TwoFactorTest`. 286 tests.
- Files: `app/Support/Totp.php`, `app/Services/TwoFactorService.php`, `app/Models/{LoginEvent,User,Source}.php`, `app/Http/Controllers/{Auth/AuthController,Admin/SecurityController}.php`, `database/migrations/2026_10_09_*`, `routes/{web,console}.php`, `resources/views/{auth/two-factor,admin/security/index,layouts/admin,components/icon}.blade.php`, `public/css/admin.css`, `lang/mk.json`, `lang/mk/validation.php`, `composer.{json,lock}`, `docs/**`, `tests/**`.

### 2026-10-08 — CI, backups, error tracking
- **CI:** GitHub Actions run the tests, the relay tests and a MySQL job (migrate up / reset / up, seed, backup) on every push to `main` and every pull request.
- **Backups:** `iptv:backup` (nightly 02:30, `BackupService`) writes a gzipped `mysqldump` to `BACKUP_DISK` (local or off-site), skips the data of rebuildable tables, keeps `BACKUP_KEEP_DAYS` (14) and always the newest. Failure → `backup_failed` alert. The Docker image now has the MariaDB client + connector (needed for MySQL 8.4's password plugin). Restore tested into a scratch database. SQLite files are copied too (local dev).
- **Error tracking:** `sentry/sentry-laravel`, off without `SENTRY_LARAVEL_DSN`. `SentryScrubber` removes tokens, passwords, cookies and provider login/paths before sending.
- **Logs:** `.env.example` uses daily log files kept 14 days (`LOG_STACK=daily`).
- Tests: `BackupTest`, `SentryScrubberTest`. 263 tests.
- Files: `.github/workflows/ci.yml`, `app/Services/BackupService.php`, `app/Console/Commands/BackupCommand.php`, `app/Support/SentryScrubber.php`, `app/Models/StreamAlert.php`, `config/{iptv,sentry}.php`, `bootstrap/app.php`, `routes/console.php`, `Dockerfile`, `docker-compose.yml`, `.env.example`, `composer.{json,lock}`, `lang/mk.json`, `docs/**`, `tests/**`.

### 2026-10-08 — Playlist is streamed
- `/m3u`, `/get.php` and `/playlist/{token}.m3u` send the playlist in 64 KB chunks (`TokenService::writeM3u`) instead of building one big string. Play URLs come from a single route template, not one `route()` call per channel. Output is unchanged, but there is no `Content-Length` header.
- Tests: playlist tests read `streamedContent()`; new test for chunking 1,500 channels.
- Files: `app/Services/TokenService.php`, `app/Http/Controllers/M3uController.php`, `docs/features/playlist-and-streaming.md`, `tests/**`.

### 2026-10-08 — Play history limits, request limits, security headers
- **Play history:** `stream_logs` older than `LOG_RETENTION_DAYS` (default 90) are deleted by `model:prune` (now also run for `StreamLog`). New indexes: `stream_logs.started_at` and `active_sessions (user_id, last_activity_at)` for the dashboard, Logs page and the connection-limit check.
- **Request limits:** `/play`, `/playlist/{token}.m3u` and `/epg/{token}.xml` answer 429 after `THROTTLE_PER_TOKEN` (60) requests a minute per link + IP, or `THROTTLE_PER_IP` (300) per IP.
- **Security headers** on panel pages (`SecurityHeaders`): `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, and HSTS over HTTPS. `.env.example` now lists `SESSION_SECURE_COOKIE`.
- Known issues: removed "No overall request limit" and the outdated "No EPG" entry.
- Tests: `SecurityHeadersTest`, limit and pruning tests in `PlayTest`. 256 tests.
- Files: `app/Http/Middleware/SecurityHeaders.php`, `app/Models/StreamLog.php`, `app/Providers/AppServiceProvider.php`, `bootstrap/app.php`, `config/iptv.php`, `routes/{player,console}.php`, `database/migrations/2026_10_08_000004_add_play_indexes.php`, `.env.example`, `docs/**`, `tests/**`.

### 2026-10-08 — TV guide, backup streams, stable channel IDs, expiry reminders
- **TV guide (EPG):**
  - New **TV guide** page with XMLTV sources (`epg_sources`), stored as `epg_channels` / `epg_programmes`. Imported nightly by `iptv:sync-epg` (`SyncEpgSource`, streaming `XMLReader`, gz, `EPG_DAYS` window, filler titles skipped).
  - Channels are matched by tvg-id (also without `@SD`) or by normalised name (`EpgService`), or set by hand (**Guide ID** on the channel form, with *On now*).
  - Apps get the guide through the playlist header (`x-tvg-url`, `/epg/{token}.xml`), `xmltv.php`, and Xtream `get_short_epg` / `get_simple_data_table`. `tvg-id` / `epg_channel_id` is the guide id.
  - Default guides: iptv-epg.org files for MK, IT, RS, HR, BA and AL (`DefaultEpgSourcesSeeder`).
- **Backup streams:** a dead channel with a working twin (the admin's **Backup channel**, or the same tvg-id) stays on and plays the twin (`using_backup_id`, `Channel::playbackChannel()`) until it recovers. *Playing a backup* badge and filter.
- **Stable channel IDs:** imported channels are keyed by tvg-id when they have one, so renames and group moves keep the channel. Existing channels are moved to the new key on the next sync without changing their id.
- **Expiry reminders:** `iptv:expiry-reminders` (daily 08:00) creates `expiring` (within `EXPIRY_REMINDER_DAYS`) and `expired` alerts, once per user and expiry date. Shown in the panel and in a *Subscriptions* group in the email.
- Schema: `epg_*` tables; `channels.epg_source_id`, `epg_id`, `epg_manual`, `backup_channel_id`, `using_backup_id`; `stream_alerts.user_id`.
- Tests: `EpgTest`, `BackupChannelTest`, `ExpiryReminderTest`, stable-id tests in `SourceSyncTest`, `refakeHttp()` helper. 250 tests.
- Files: `app/Services/{EpgImportService,EpgService,SubscriptionReminderService,StreamCheckService,SourceSyncService,TokenService,XtreamService,StreamService,StreamPreviewService,ChannelService}.php`, `app/Jobs/SyncEpgSource.php`, `app/Console/Commands/{SyncEpgCommand,ExpiryRemindersCommand}.php`, `app/Models/{EpgSource,EpgChannel,EpgProgramme,Channel,StreamAlert}.php`, `app/Http/Controllers/{M3uController,XtreamController}.php`, `app/Http/Controllers/Admin/{EpgSourceController,ChannelController}.php`, `app/Mail/StreamAlertsMail.php`, `database/migrations/2026_10_08_*`, `database/seeders/{DefaultEpgSourcesSeeder,DatabaseSeeder}.php`, `config/iptv.php`, `routes/{web,player,console}.php`, `resources/views/admin/{epg/index,channels/_form,channels/index,partials/alert-item}.blade.php`, `resources/views/layouts/admin.blade.php`, `resources/views/emails/*`, `lang/mk.json`, `lang/mk/validation.php`, `.env.example`, `CLAUDE.md`, `docs/**`, `tests/**`.

### 2026-10-07 — Satellite receiver through the NeboTV relay
- New `relay/nebotv-relay.py`, a small Python 3 program that runs on the Enigma2 receiver (Octagon SF8008 / OpenATV). It streams a channel only for links signed by the panel, allows at most `max_streams` channels (tuners), adds the box's stream login, offers the chosen bouquets as M3U (markers become categories, encrypted channels left out with `fta_only`), reports `/status`, serves picons and keeps a DuckDNS name updated. With init script, example settings and `relay/test_relay.py`.
- New source type **Satellite receiver** (`sources.type = receiver`, `relay_secret` encrypted, `transcode`, `receiver_online`, `receiver_checked_at`). Imports the relay playlist. Never stream-checked.
- `ReceiverService`: `/play`, Xtream `/live` and the admin preview send receiver channels to a signed link that expires (`RECEIVER_LINK_MINUTES`, default 180). `iptv:check-receivers` runs every 5 minutes and sends `receiver_down` / `receiver_up` alerts.
- Import errors never store the relay secret.
- Docs: new `docs/features/receiver.md` (setup guide). Tests: `ReceiverTest` (14) and 9 relay tests. 223 tests.
- Files: `relay/*`, `app/Services/{ReceiverService,StreamService,StreamPreviewService,SourceSyncService,StreamCheckService}.php`, `app/Console/Commands/CheckReceiversCommand.php`, `app/Models/{Source,StreamAlert}.php`, `app/Mail/StreamAlertsMail.php`, `app/Http/Controllers/Admin/SourceController.php`, `database/migrations/2026_10_07_000009_add_receiver_columns_to_sources_table.php`, `config/iptv.php`, `routes/console.php`, `resources/views/admin/sources/{_form,index}.blade.php`, `resources/views/emails/*`, `lang/mk.json`, `lang/mk/validation.php`, `.env.example`, `CLAUDE.md`, `docs/**`, `tests/Feature/ReceiverTest.php`.

### 2026-10-07 — Admin-only middleware, admin from .env, per-source stream check, one check at a time
- Admin routes now also require `role = admin` (`EnsureAdmin`, alias `admin`). Other signed-in users are signed out.
- `AdminSeeder` reads `ADMIN_USERNAME` / `ADMIN_PASSWORD` from `.env`. Without a password: `admin123` locally, otherwise a random one printed once. It never changes an existing user. The hard-coded password is removed (it is still in git history: change it where it was used).
- Sources: **Check streams** switch (`sources.check_streams`, default on). Off = the stream check skips the source, and channels it had switched off come back. For channels that play only in some countries.
- Stream check: only one at a time (`stream-check:pending` cache counter). The button says when a check is already running, and the schedule doesn't pile up checks while the queue is stopped.
- Docs: replaced stale `admin / admin123` mentions. Tests: `AdminAccessTest`, more `StreamCheckTest`. 209 tests.
- Files: `app/Http/Middleware/EnsureAdmin.php`, `bootstrap/app.php`, `routes/web.php`, `database/seeders/AdminSeeder.php`, `database/migrations/2026_10_07_000008_add_check_streams_to_sources_table.php`, `app/Models/Source.php`, `app/Services/{SourceService,StreamCheckService}.php`, `app/Jobs/CheckStreams.php`, `app/Console/Commands/CheckStreamsCommand.php`, `app/Http/Controllers/Admin/{ChannelController,SourceController}.php`, `resources/views/admin/sources/_form.blade.php`, `lang/mk.json`, `.env.example`, `README.md`, `CLAUDE.md`, `docker-compose.yml`, `docker/php/entrypoint.sh`, `.claude/skills/run-app/SKILL.md`, `docs/**`, `tests/**`.

### 2026-10-07 — Reliability: login limits, confirmed failures, background task monitoring, stream headers
- **Login limits:** admin sign-in is locked for a minute after 5 wrong passwords (username + IP). App logins (`/m3u`, `/get.php`, `/player_api.php`, `/live`) are blocked for 10 minutes after 20 wrong attempts from one IP.
- **Stream check:** failed streams are retried once after 5 s before they count as offline, and the reason is stored (`channels.stream_error`: HTTP code, timeout, connect, web page, empty). It is shown in the badge tooltip, the dashboard and alerts/emails ("stopped working: No answer in time"). Full check: 3,619 online (was 3,578).
- **Background task monitoring** (`HealthService`, `iptv:heartbeat` every minute): `queue_stuck` and `jobs_failed` alerts (panel and email), a red dashboard banner when the scheduler or queue stops, and `GET /health` (200/503) for an uptime monitor.
- **Stream headers:** imports keep `http-user-agent` / `http-referrer` from `#EXTINF` attributes and `#EXTVLCOPT` lines (`channels.http_user_agent`, `http_referrer`; 299 channels). The M3U outputs them as `#EXTVLCOPT` lines and the stream check sends them (183 of those 299 now online).
- Tests: `LoginThrottleTest`, `HealthTest`, more `StreamCheckTest`, `StreamAlertTest`, `SourceSyncTest`, `M3uParserTest`. `Sleep` is faked in `Tests\TestCase`. 198 tests.
- Files: `app/Services/{StreamCheckService,HealthService,XtreamService,M3uParser,SourceSyncService,TokenService}.php`, `app/Http/Controllers/{HealthController,Auth/AuthController,Admin/DashboardController}.php`, `app/Console/Commands/HeartbeatCommand.php`, `app/Models/{Channel,StreamAlert}.php`, `app/Mail/StreamAlertsMail.php`, `database/migrations/2026_10_07_000006_add_stream_headers_and_errors.php`, `routes/{console,player}.php`, `resources/views/admin/{dashboard,channels/index}.blade.php`, `resources/views/emails/*`, `lang/mk.json`, `tests/**`, `docs/**`.

### 2026-10-07 — Connection limit counts devices; switching channels works
- Fix: a user with `max_connections` 1 who changed channel got `403 Access denied or connection limit reached` for 5 minutes (VLC showed "Your input can't be opened").
- The limit now counts **devices** (client IP + user agent): a device can switch channels freely, and its session moves to the new channel. Any other device needs a free slot, even in the same home and even on the same channel (the old "same channel doesn't use a slot" exception is gone).
- `active_sessions` is now one row per user + device. Migration `add_device_to_active_sessions_table` (nullable `ip`, `user_agent`).
- Tests: channel switching, second device in the same home, limit of 2. 201 tests.
- Files: `app/Services/StreamService.php`, `app/Models/ActiveSession.php`, `database/migrations/2026_10_07_000007_add_device_to_active_sessions_table.php`, `tests/Feature/{PlayTest,XtreamTest}.php`, `docs/**`.

### 2026-10-07 — Login with server, username and password (Xtream Codes API)
- TV apps can now log in with **server URL + username + password** as well as the M3U link: `player_api.php` (login info, live categories, live streams; VOD/series/EPG answer empty), `get.php` (M3U), `xmltv.php` (empty guide) and `/live/{username}/{password}/{id}.ts|m3u8` (same access checks, limits and logging as `/play`). New `XtreamService`, `XtreamController`.
- The user page has a new **Log in with username and password** card: server URL, username, password note, host and port, and setup steps. It replaces the old `/m3u?username=` hint.
- Player endpoints (`/m3u`, `/playlist`, `/play` and the Xtream ones) moved to `routes/player.php`, loaded without the `web` middleware group, so TV apps no longer create a session row on every request. `/m3u` with missing credentials now returns 401 instead of a validation redirect.
- Docker nginx: every `*.php` URL now runs `public/index.php`, so `/player_api.php` reaches Laravel. Restart the `web` container after updating.
- New `Channel` scopes `availableTo($user)` and `playlistOrder()`, shared by the M3U and the API.
- Tests: `XtreamTest`. 182 tests.
- Files: `app/Services/{XtreamService,TokenService}.php`, `app/Http/Controllers/{XtreamController,M3uController}.php`, `app/Http/Controllers/Admin/UserController.php`, `app/Models/Channel.php`, `routes/{player,web}.php`, `bootstrap/app.php`, `docker/nginx/default.conf`, `resources/views/admin/users/show.blade.php`, `public/css/admin.css`, `lang/mk.json`, `tests/Feature/XtreamTest.php`, `docs/**`.

### 2026-10-07 — Header alerts bell redesign
- The header bell is now a round, bordered button with a larger icon. The unread count sits on a ringed red badge, the bell wiggles on hover (off with reduced motion), and the button is highlighted while the menu is open. The menu fades in, has rounder corners, and the empty state shows an icon.
- Files: `resources/views/admin/partials/notifications.blade.php`, `public/css/admin.css` (`.notif-*`, local `--notif-ring` variable, no new colour tokens).

### 2026-10-07 — Alert email design
- Alert emails got a branded, email-safe layout: logo (inline CID image), headline with the problem count, alerts grouped by type with status pills, button to the alerts page, inbox preview text, and a plain-text version. The test email uses the same layout.
- Tests: email grouping/order and rendering. 171 tests.
- Files: `app/Mail/StreamAlertsMail.php`, `app/Console/Commands/SendAlertsCommand.php`, `resources/views/emails/stream-alerts{,-text}.blade.php`, `lang/mk.json`, `tests/Feature/StreamAlertTest.php`, `docs/features/alerts.md`.

### 2026-10-07 — Watch channels in the admin panel
- New **Watch** button on each row of the Channels page. It opens a player dialog so admins can see whether a channel is any good. HLS plays with hls.js and MPEG-TS with mpegts.js (jsDelivr, loaded on first use), trying the other if the first fails. When nothing plays, the dialog shows why. The stream address is shown with a copy button, for VLC.
- Streams go through the panel (`GET /admin/channels/{channel}/preview`, `StreamPreviewService`), so providers without CORS headers or on plain http still play. HLS playlists are rewritten so segments, keys and variants also go through the panel. Proxied URLs are HMAC-signed per channel with the app key, so the endpoint can't fetch other addresses. Live MPEG-TS stops after 30 minutes or when the player closes.
- Fix: the pagination partial used `__('Pagination')`, which on a case-insensitive filesystem (Docker on macOS) loads Laravel's `pagination.php` array and crashes any paginated page. It now uses `__('Page navigation')`.
- New CSS tokens `--player-bg` / `--player-text` (same in light and dark), `.modal-wide`, `.player`, `.player-status`. Macedonian strings added.
- Tests: `StreamPreviewTest` (auth, TS pass-through, playlist rewriting, signed segments, tampered signatures, provider errors, non-http URLs).
- Files: `app/Services/StreamPreviewService.php`, `app/Http/Controllers/Admin/ChannelController.php`, `routes/web.php`, `resources/views/admin/channels/index.blade.php`, `resources/views/admin/partials/pagination.blade.php`, `public/css/admin.css`, `lang/mk.json`, `tests/Feature/StreamPreviewTest.php`, `docs/{routes,architecture,known-issues,CHANGELOG}.md`, `docs/features/admin-panel.md`.

### 2026-10-07 — Stream check and alerts
- **Stream check** (`StreamCheckService`, `CheckStreams` job, `iptv:check-streams {source?} {--watched} {--now}`): concurrent GET of every enabled channel, keeping only the first 4 KB (`App\Support\CappedStream`). Dead channels are switched off with `auto_disabled`, and switched back on when they work again. Admin-disabled channels are left alone. Runs daily at 04:00, from the **Check streams** button on the Channels page, and every 15 minutes for watched channels. First run: 2,220 of 5,798 channels switched off.
- **Alerts:** a **Send me alerts** switch on channels (form + bulk actions) and sources. Watched channels that stop or start working, and watched sources whose import fails, create a `StreamAlert`. They show in a header bell with an unread count, on a new Alerts page and in a dashboard **Watched channels** card. `iptv:send-alerts` (every 5 minutes) emails them as one digest to `ALERT_MAIL_TO`. `.env.example` documents Brevo SMTP. `iptv:send-alerts --test` sends a test email.
- Channels list: *Not working* and *Alerts on* status filters, red *Not working* badge, bell icon on watched channels. Dashboard Channels stat links to the switched-off channels.
- Schema: `channels.stream_status`, `stream_checked_at`, `auto_disabled`, `alert_on_down`; `sources.alert_on_down`; new `stream_alerts` table.
- Tests: `StreamCheckTest`, `StreamAlertTest`. 169 tests.
- Files: `app/Services/{StreamCheckService,ChannelService,SourceSyncService}.php`, `app/Support/CappedStream.php`, `app/Jobs/CheckStreams.php`, `app/Console/Commands/{CheckStreamsCommand,SendAlertsCommand}.php`, `app/Mail/StreamAlertsMail.php`, `app/Models/{Channel,Source,StreamAlert}.php`, `app/Http/Controllers/Admin/{AlertController,ChannelController,SourceController,DashboardController}.php`, `app/Providers/AppServiceProvider.php`, `config/iptv.php`, `database/migrations/2026_10_07_00000{4,5}_*.php`, `routes/{web,console}.php`, `resources/views/admin/{alerts/index,partials/notifications,partials/alert-item,channels/index,channels/_form,sources/_form,dashboard}.blade.php`, `resources/views/layouts/admin.blade.php`, `resources/views/emails/stream-alerts.blade.php`, `resources/views/components/icon.blade.php` (`bell`), `public/css/admin.css`, `lang/mk.json`, `lang/mk/validation.php`, `.env.example`, `docs/**`, `CLAUDE.md`.

### 2026-10-07 — Default sources
- New `DefaultSourcesSeeder`: two hand-checked Macedonian playlists bundled in `database/seeders/playlists/` (bouquets **Macedonia TV** and **Macedonia Music**, categories `MK | National`, `MK | Regional`, `MK | Music`), plus auto-syncing public lists from Free-TV (bouquet **World**) and iptv-org (News, Sports, Movies, Kids, Music, Documentary). Imports are queued.
- `DatabaseSeeder` runs it only while there are no sources, so deleted defaults don't return on deploy. Running the seeder directly re-adds missing ones by name.
- Tests: `SeederTest` covers seeding, queued imports, idempotency and the Macedonian import. The admin test no longer assumes the `admin` username. 124 tests.
- Files: `database/seeders/{DatabaseSeeder,DefaultSourcesSeeder}.php`, `database/seeders/playlists/*.m3u`, `tests/Feature/SeederTest.php`, `docs/{getting-started,deployment,known-issues,CHANGELOG}.md`, `docs/features/sources.md`.

### 2026-10-07 — Language flags and light/dark mode switch
- The language picker is now a menu with flag images (`public/images/flags/{gb,mk}.svg`) instead of a plain select. `config('app.locales')` entries are now `['name' => …, 'flag' => …]`.
- New light / dark / system switch next to it (sidebar and sign-in page). The choice is stored in an unencrypted `theme` cookie, rendered as `<html data-theme>` by `App\Support\Theme`, applied instantly by JS, with `POST /theme` (`ThemeController`) as the no-JS fallback. The dark CSS tokens now apply for OS dark mode (unless light is forced) and for `data-theme="dark"`.
- Both controls live in the new `partials/preferences` partial (replaces `partials/locale-switcher`). New icons: `sun`, `moon`, `monitor`, `chevron-down`. Macedonian strings: Theme, Light, Dark, System.
- Tests: `ThemeTest` (default, cookie set/cleared, rendering, invalid values) and a flag test in `LocaleTest`. 120 tests.
- Docs: new `docs/features/theme.md`; localization, routes, architecture, admin-panel, authentication, getting-started; CLAUDE.md and skills mention checking both themes.
- Files: `config/app.php`, `bootstrap/app.php`, `routes/web.php`, `app/Support/Theme.php`, `app/Http/Controllers/ThemeController.php`, `resources/views/partials/preferences.blade.php`, `resources/views/{layouts/admin,auth/login}.blade.php`, `resources/views/components/icon.blade.php`, `public/css/admin.css`, `public/images/flags/*`, `lang/mk.json`, `tests/Feature/{ThemeTest,LocaleTest}.php`, `docs/**`, `CLAUDE.md`, `.claude/skills/*`.

### 2026-10-07 — Shared hosting support
- New `QUEUE_SCHEDULED_WORKER` env var (`config('queue.scheduled_worker')`, default `false`). When `true`, the scheduler runs `queue:work --stop-when-empty --tries=1 --timeout=600 --max-time=240` every minute (in the background, never overlapping, 15-minute lock), so one `schedule:run` cron job is enough on hosting without a long-running worker. Docker keeps its `queue` container and leaves it off.
- `SyncSource::failed()`: a source whose import job was killed or timed out is now marked **Failed** ("The import was stopped before it finished.") instead of staying **Syncing**.
- Docs: new `docs/deployment.md` (Hostinger / shared hosting guide).
- Tests: scheduled worker on/off, job failure handling. 114 tests.
- Files: `config/queue.php`, `routes/console.php`, `app/Jobs/SyncSource.php`, `.env.example`, `tests/Feature/SourceSyncTest.php`, `README.md`, `CLAUDE.md`, `docs/{deployment,README,getting-started,routes,CHANGELOG}.md`, `docs/features/sources.md`.

### 2026-10-07 — NeboTV name, logo and favicons
- The panel is now called **NeboTV**: sidebar, mobile top bar, sign-in page and page titles use `config('app.name')` (`APP_NAME=NeboTV`, also the default in `config/app.php`) instead of the translated "IPTV Panel" string, which was removed from `lang/mk.json`.
- New logo: `nebotv` icon (TV with a star, "nebo" = sky) in the brand mark. Favicons (`favicon.svg`, `favicon.ico`), `apple-touch-icon.png`, `icon-192.png`, `icon-512.png` and `site.webmanifest` so it can be added to a phone home screen; linked via the new `admin/partials/favicons` partial.
- Wordmark: `<x-brand-name />` shows "Nebo" + gradient "TV" in the sidebar, top bar and sign-in page (new CSS variables `--brand-from`, `--brand-to`, `--sidebar-brand-from`, `--sidebar-brand-to`). Full logo files `public/logo{,-dark}.{svg,png}` for use outside the panel.
- Changing `APP_NAME` changes the session cookie name, so admins are signed out once after updating `.env`.
- Tests: branding check in `AuthTest`. 111 tests.
- Files: `config/app.php`, `.env.example`, `resources/views/layouts/admin.blade.php`, `resources/views/auth/login.blade.php`, `resources/views/components/{icon,brand-name}.blade.php`, `public/css/admin.css`, `public/logo{,-dark}.{svg,png}`, `resources/views/admin/partials/favicons.blade.php`, `public/{favicon.svg,favicon.ico,apple-touch-icon.png,icon-192.png,icon-512.png,site.webmanifest}`, `lang/mk.json`, `tests/Feature/AuthTest.php`, `README.md`, `CLAUDE.md`, `docs/**`.

### 2026-10-07 — English and Macedonian translations
- Panel and sign-in page are translatable; English (default) and Macedonian ship. A language switcher (sidebar + sign-in page) posts to the new `POST /locale` route, which stores a `locale` cookie; `SetLocale` middleware (web group) applies it, falling back to `APP_LOCALE`. Supported languages are listed in `config('app.locales')`.
- Every view string, flash message and custom validation message now goes through `__()` / `trans_choice()` (English text as key). Dates with month names use `translatedFormat()`; relative dates follow the locale through Carbon.
- Macedonian strings in `lang/mk.json`; Laravel's validation/auth/pagination/password messages and form field names in `lang/mk/*.php`.
- Bulk-action flash messages and channel/user counts use proper plural forms instead of `str()->plural()`.
- Tests: `LocaleTest` (switching, cookie fallback, translated pages, flash and validation messages, plurals, every code string has a Macedonian entry with the same placeholders). 110 tests.
- Docs: new `docs/features/localization.md`; CLAUDE.md and the `update-docs`, `add-admin-resource`, `write-tests`, `run-app` skills now require translated strings.
- Files: `config/app.php`, `bootstrap/app.php`, `routes/web.php`, `app/Http/Middleware/SetLocale.php`, `app/Http/Controllers/LocaleController.php`, `app/Http/Controllers/{Admin/*,Auth/AuthController}.php`, `resources/views/**`, `public/css/admin.css`, `lang/**`, `tests/Feature/LocaleTest.php`, `docs/**`, `CLAUDE.md`, `.claude/skills/*`.

### 2026-10-07 — v1: M3U sources, admin redesign, Docker
- **Sources:** import provider playlists by URL or upload (`sources` table, `Source` model, `SourceService`, `SourceSyncService`, `M3uParser`, `SyncSource` job, `iptv:sync-sources` command scheduled hourly with `--due`). Channels are upserted by `(source_id, external_key)`, keep the admin's enabled/disabled choice, vanished ones are deleted. Optional VOD skipping, auto-add to a (new) bouquet, daily auto-refresh.
- **Channels:** new columns `source_id`, `external_key`, `tvg_id`, `sort_order`; URLs up to 2048 chars. List with search/filters and bulk enable/disable/add to bouquet/remove from bouquet/delete, including "all matching".
- **Bouquets:** form adds whole categories instead of a checkbox per channel (scales to big lists).
- **Users:** per-user playlist link `/playlist/{token}.m3u` (`users.playlist_token`, regenerable), user page with copy button, TV setup steps and warnings, search/status filters, can't delete yourself, unticking all bouquets now clears them, create/update redirect to the user page.
- **Playlist:** channels de-duplicated across bouquets, ordered by category/source order/name, `tvg-id` from the source, play URLs carry the stream extension (`.m3u8`, `.ts`…), playback tokens renewed when under 7 days left.
- **Dashboard:** real stats (active subscribers, enabled channels, watching now, plays 24 h), source status, expiring subscriptions; removed fake trend labels.
- **Redesign:** new `public/css/admin.css` design system (light/dark, responsive drawer), `<x-icon>` component, shared flash/errors/pagination/status partials, clear labels on every page, new login page. Removed `welcome.blade.php` and the unused Tailwind form partials.
- **Docker:** `Dockerfile`, `docker-compose.yml` (nginx, PHP-FPM, MySQL 8.4, queue worker, scheduler), `docker/` config, `.dockerignore`. The app container migrates and seeds on start.
- **Test safety:** `phpunit.xml` forces SQLite via `<server>` too (Docker env vars otherwise won), and `Tests\TestCase::beforeRefreshingDatabase()` refuses to wipe any non-memory database.
- Fixed the channels migration `down()` for MySQL (drop FK before the unique index).
- `.env.example`: `DB_QUEUE_RETRY_AFTER=900`.
- Tests: 101 (parser, sync, sources admin, bulk actions, users, playlist link, ordering/dedupe, extensions), run on SQLite and MySQL 9.7.
- Files: `app/Models/{Source,Channel,User,ActiveSession}.php`, `app/Services/*`, `app/Jobs/SyncSource.php`, `app/Console/Commands/SyncSources.php`, `app/Http/Controllers/**`, `routes/*.php`, `database/migrations/2026_10_07_*`, `resources/views/**`, `public/css/admin.css`, `Dockerfile`, `docker-compose.yml`, `docker/**`, `.dockerignore`, `phpunit.xml`, `tests/**`, `README.md`, `docs/**`.

### 2026-10-07 — .gitignore additions
- Ignore IDE helper files, `/coverage`, `.claude/settings.local.json` and `CLAUDE.local.md`. `CLAUDE.md` and `.claude/skills/` stay tracked.
- Files: `.gitignore`

### 2026-10-07 — Bug fixes and first test suite
- `/m3u` no longer crashes: added the `User::accessTokens()` relation.
- Expired accounts now get `403 Account expired` from `/m3u`; disabled accounts get `403 Account disabled`.
- M3U output is escaped: `"` becomes `'` inside attributes, and line breaks become spaces.
- Connection limit: the same-channel exception only applies to sessions active in the last 5 minutes. `started_at` is kept while a session stays active and reset after it goes stale. Stale `active_sessions` rows are pruned every 15 minutes (`ActiveSession` is `Prunable`, scheduled in `routes/console.php`). The timeout is `StreamService::SESSION_TIMEOUT_MINUTES`.
- Channels can be deactivated: the create/edit forms send a hidden `is_active=0`.
- Bouquet create/edit and the categories page now show validation errors (new partial `admin/partials/errors`).
- Removed unimplemented resource routes (`show` everywhere; `create`/`edit` for categories).
- `UserFactory` matches the schema, with `admin()`, `disabled()` and `expired()` states. `DatabaseSeeder` now calls `AdminSeeder`, which is idempotent. Fixed the `SampleDataSeeder` namespace.
- Tests: enabled `RefreshDatabase` and added the `subscribedChannel()` helper in `tests/Pest.php`. 47 feature tests cover auth, playlist, play/limits/pruning, channel admin, validation display and seeders.
- Files: `app/Models/User.php`, `app/Models/ActiveSession.php`, `app/Services/TokenService.php`, `app/Services/StreamService.php`, `app/Http/Controllers/M3uController.php`, `routes/web.php`, `routes/console.php`, `resources/views/admin/{channels/create,channels/edit,bouquets/create,bouquets/edit,categories/index,partials/errors}.blade.php`, `database/factories/UserFactory.php`, `database/seeders/*`, `tests/**`.

### 2026-10-07 — Documentation baseline
- Added `CLAUDE.md`, `docs/` (architecture, data model, routes, features, known issues) and Claude skills in `.claude/skills/` (`update-docs`, `add-admin-resource`, `write-tests`).

## Pre-docs (reconstructed from the code, ~2026-04)

- Laravel 13 skeleton; users table changed to `username`/`status`/`role`/`expiration_date`/`max_connections`.
- Schema: categories, channels, bouquets, pivots, access_tokens, active_sessions, stream_logs.
- Services: User, Channel, Bouquet, Token, Stream.
- Admin auth (admin-only login).
- Admin panel: dashboard, users (with status toggle), channels, categories, bouquets, activity logs; custom dark theme `public/css/admin.css`.
- Public endpoints: `/m3u` playlist and `/play/{channel_id}/{token}` redirect with access checks, connection limit and logging.
- Seeders: `AdminSeeder`, `SampleDataSeeder`.
