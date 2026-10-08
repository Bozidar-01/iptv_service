# Admin Panel

**Branding:** the panel is called **NeboTV** ("nebo" = sky). The name comes from `config('app.name')` (`APP_NAME`, default `NeboTV`) and is shown in every `<title>` and, as a wordmark via `<x-brand-name />` (a trailing "TV" gets the `--brand-from`/`--brand-to` gradient), in the sidebar, the mobile top bar and the sign-in page; it is a brand name, so it is not translated. The logo is the `nebotv` icon (a TV with a star) on the indigo gradient `.brand-mark`. Favicons and home-screen icons live in `public/` (`favicon.svg`, `favicon.ico`, `apple-touch-icon.png`, `icon-192.png`, `icon-512.png`, `site.webmanifest`) and are linked from `admin/partials/favicons`, included by the layout and the sign-in page. Full logo (icon + wordmark) for use outside the panel: `public/logo.svg` / `logo.png` (light backgrounds) and `public/logo-dark.svg` / `logo-dark.png` (dark backgrounds); the SVGs use system fonts, so use the PNGs where exact lettering matters.

**Layout:** `resources/views/layouts/admin.blade.php`. Sidebar navigation (Overview / Content / Subscribers), an alerts bell in the header ([alerts.md](alerts.md)), flash messages and validation errors rendered once for every page (`admin/partials/flash`), a language menu with flags (English / Macedonian, see [localization.md](localization.md)) and a light / dark / system switch ([theme.md](theme.md)) above the account footer, and a slide-out menu below 860 px. Styling lives in `public/css/admin.css` (see [architecture.md](../architecture.md#ui-conventions)). The theme follows the OS setting unless the admin picks light or dark.

Shared behaviours (in the layout script):
- `<form data-confirm="…">` asks for confirmation before submitting (all deletes).
- `<button data-copy="#input">` copies an input's value.

## Dashboard (`admin.dashboard`)
- Stats: active subscribers / total, enabled channels / total, **watching now** (sessions active in the last 5 min), plays in 24 h.
- A red banner when background tasks stopped: the scheduler hasn't run for 5 minutes (or never), or queued jobs have waited 15 minutes ([alerts.md](alerts.md#background-tasks-healthservice)).
- A "Get started" hint while there are no sources.
- Recent activity (8 latest plays), **Watched channels** that are not working ([alerts.md](alerts.md)), source list with sync status, subscriptions expiring within 7 days.
- The Channels stat links to the channels the stream check switched off.

## Sources (`admin.sources.*`, `POST sources/{source}/sync`)
Three kinds: **From URL**, **Upload file**, **Satellite receiver** (relay address, shared secret with copy button and a generated suggestion, transcoding switch; see [receiver.md](receiver.md)). Receiver rows show *receiver online / offline / not checked yet*. The *Live TV only* and *Check streams* options are hidden for receivers.

List with type/host, status badge (Not synced / Queued / Syncing / Synced / Failed with the error), channel counts (linked to the filtered channel list), bouquet, last sync, and Sync / Edit / Delete. See [sources.md](sources.md).

## Channels (`admin.channels.*`, `POST channels/bulk`)
- 50 per page, sorted by name, with logo, upstream host, category, source, bouquet count and status.
- **Filters** (GET, kept in pagination links): search by name, category (or *Uncategorized*), source (or *Added manually*), bouquet, status (*Enabled*, *Disabled*, *Not working* = switched off by the stream check, *Alerts on*).
- **Check streams** button: queues a stream check of all channels, or of the filtered source ([alerts.md](alerts.md)). Channels switched off by it show a red *Not working* badge.
- **Bulk actions:** tick rows (or the header box for the whole page). Once a full page is selected, *Select all N matching* applies the action to every channel matching the current filters. Actions: enable, disable, add to bouquet, remove from bouquet, turn alerts on, turn alerts off, delete. Adding skips channels already in the bouquet.
- **Watch** (play button on each row) opens a player dialog to check a channel by eye. The stream goes through the panel (`GET channels/{channel}/preview`, `StreamPreviewService`), so provider streams without CORS headers or on plain http still play. HLS (`.m3u8`/`.m3u`) uses hls.js and MPEG-TS uses mpegts.js, both loaded from jsDelivr on first use. If the first one fails, the other is tried. When nothing plays, the dialog shows the panel's reason (provider error code, web page instead of a stream, no answer) or a generic "format browsers can not play" message. The dialog also shows the stream address with a copy button, for VLC. Live MPEG-TS is passed on for at most 30 minutes per open player (`StreamPreviewService::MAX_SECONDS`) and ties up one PHP worker while it plays.
- Create/edit form: name, stream URL, optional category and logo, enabled toggle (hidden `0` fallback), **Send me alerts**. Editing also has **Guide ID** (with the automatic match and *On now*) and **Backup channel** (channels with the same name or guide id). Editing an imported channel shows a notice that the next sync overwrites name/URL/logo/category but keeps enabled/disabled.

## TV guide (`admin.epg.*`)
Guide sources with status and counts, coverage stats (enabled channels with a guide / with upcoming programmes), **Match channels again**, and an add form. See [epg.md](epg.md).

## Alerts (`admin.alerts.index`, `POST alerts/read`)
All alerts, newest first, 50 per page, with **Mark all as read**. See [alerts.md](alerts.md).

## Categories (`admin.categories.*`, only index/store/update/destroy)
Table with channel and enabled counts, *View channels* (filtered list), rename and create in dialogs, delete (channels keep existing without a category).

## Bouquets (`admin.bouquets.*`)
- List with channel count (and enabled count when different), user count, and a link to the bouquet's channels.
- Create/edit: name plus **add channels by category** (filterable tiles with channel counts). Ticked categories' channels are added; nothing is removed. Individual channels are managed with Channels-page bulk actions.

## Resellers
A **reseller** (`users.role = reseller`) signs in to the panel but only sees and manages their own users (`users.owner_id`). Set up by an admin in the user form: *Role: Reseller*, *Most users* (empty = no limit), *Most streams per user* (empty = no limit) and the **Bouquets**, which for a reseller means the bouquets they may give to their users (the same pivot; it also gives them a playlist of their own to try things). The admin's *Users* form can also put a subscriber under a reseller (*Reseller*), and the user list shows *Reseller: name* and can be filtered with `?reseller=<id>`.

What a reseller gets: **Dashboard** (own users, expiring soon, watching now, recent plays; "You have N of M users"), **Users** (only their own, create/edit/enable-disable/delete/new playlist link/disconnect devices), **Activity** (plays of their users), **Alerts** (alerts about their users, including *asks to renew*; read/unread stays with the admins, so no unread count and *Mark all as read* does nothing for them) and **Security** (own two-factor and sign-ins). Everything else (Sources, Channels, Categories, Bouquets, TV guide, Statistics) answers **403**.

Rules, enforced on the server whatever is posted (`UserController::rules()` / `applyRestrictions()`): a new user is always a subscriber with `owner_id` = the reseller, role/owner/limits can't be changed by a reseller, they can only hand out their own bouquets (bouquets the admin gave a user are kept when the reseller saves), `max_connections` can't exceed their cap, and they can't add a user past *Most users*. Every `{user}` in a panel URL is scoped (`User::scopeVisibleTo`, `Route::bind('user')`): someone else's user, an admin or another reseller is a **404**, for show, edit, update, delete, toggle, playlist link and device disconnect alike. `EnsureAdmin` / `EnsureStaff` run before the binding.

An admin can't demote themselves, can't change a reseller that still has users into something else, and can't delete one that still has users (the error says to move or delete them first); if the account is removed some other way the users stay, without an owner. There is **no credit or price system**: a reseller can set any expiry date for their users.

## Users (`admin.users.*`, `POST users/{user}/toggle`, `POST users/{user}/playlist-token`)
- List: search by username, status filter (active / expired / disabled), bouquets, expiry, max streams, and quick actions (playlist, enable/disable, edit, delete; you can't delete yourself).
- Form: username, password (text field, min 6, blank on edit = keep), role (subscriber / admin), status, expiry date with +1 month / +3 months / +1 year / Never shortcuts, simultaneous streams, bouquets (tiles with channel counts). Unticking all bouquets clears them.
- **User page** (`admin.users.show`; create and update redirect here):
  - **Playlist link** `/playlist/{token}.m3u` with copy button and *New link* (regenerates the token; the old link stops working).
  - Warnings when the panel is opened on localhost (link unreachable from a TV), when the account is disabled/expired, or when the playlist would be empty.
  - TV setup steps (TiviMate, IPTV Smarters, OTT Navigator, VLC).
  - **Log in with username and password** (Xtream Codes): server URL (`scheme://host:port` of the current request) and username with copy buttons, a note that the password is the one set for the user (link to *Change password*), host and port separately, and setup steps. See [playlist-and-streaming.md](playlist-and-streaming.md#3-xtream-codes-api-login-with-server--username--password).
  - **Page for this user:** the link `/me/{playlist token}` with a copy button, to send to the subscriber (see below).
  - Subscription details (expiry, active/max streams, channel count, bouquets) and the 10 latest plays. "Active now" counts only devices that played within the last 5 minutes.
  - **Devices watching now:** one row per device (channel, IP, player, since) with **Disconnect** and, for more than one device, **Disconnect all**. Disconnecting deletes the device's session (freeing its slot) and keeps that device (IP + player) from starting a stream for `StreamService::DEVICE_BLOCK_MINUTES` (15). The block is kept in the cache. A player that streams straight from the provider (redirect) keeps its current stream until it reconnects, because the panel is no longer in the path; through *Hide provider address* HLS parts are refused at once and a running MPEG-TS stream stops at the next 30-second tick.

## Subscriber page (`/me/{playlist token}`)
No sign-in: the subscriber opens their personal link (the same secret as the playlist link, shown on the user page as *Page for this user*; a new playlist link retires it). `SelfServiceController`, routes in `routes/player.php` (no session, no cookies, no CSRF), limit 30 requests a minute per link + IP (`me` limiter). Shows status (active / expired / disabled) and the expiry date, the playlist link with a copy button, how to set it up, the Xtream server and username (**never the password**: it is stored hashed; the page says it is the one they were given), the channel count and bouquet names, and "streams in use: N of max". **Ask to renew** appears when the subscription ends within 30 days or has ended (not for disabled accounts or ones without an end date): it creates a `renewal_requested` alert for the admins (panel, link to the user, *Subscriptions* group in the email digest), at most one per user per 24 hours; the page then confirms ("sent" / "already asked today"). Language: `?lang=en|mk`, otherwise the browser's language, nothing stored. Unknown or retired links, and admin accounts, get a plain 404. Marked `noindex`, `no-referrer`.

## Statistics (`admin.statistics.index`)
What users watch, from the play history (`stream_logs`, kept `LOG_RETENTION_DAYS` = 90 days). Period menu: last 7 / 30 / 90 days (`?days=`, anything else = 30). Shows total **plays** (one stream start; switching channel counts again) with the daily average, **viewers** (users who watched), **channels watched**, plays per day and per hour of the day as column charts (hover for the number; times are in `APP_TIMEZONE`, UTC by default), the 10 most played channels (plays, distinct viewers) and the 10 most active users (plays, distinct channels). `StatisticsService` uses a fixed number of grouped queries over the `started_at` index (never loads rows) and caches the result for 5 minutes, so new plays can take up to 5 minutes to appear. Plays of a deleted channel or user are deleted with them.

## Activity (`admin.logs.index`)
Stream starts, newest first, 50 per page, filterable by user (`?user=`), with IP and player user agent.
