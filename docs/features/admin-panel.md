# Admin Panel

**Branding:** the panel is called **NeboTV** ("nebo" = sky). The name comes from `config('app.name')` (`APP_NAME`, default `NeboTV`) and is shown in every `<title>` and, as a wordmark via `<x-brand-name />` (a trailing "TV" gets the `--brand-from`/`--brand-to` gradient), in the sidebar, the mobile top bar and the sign-in page; it is a brand name, so it is not translated. The logo is the `nebotv` icon (a TV with a star) on the indigo gradient `.brand-mark`. Favicons and home-screen icons live in `public/` (`favicon.svg`, `favicon.ico`, `apple-touch-icon.png`, `icon-192.png`, `icon-512.png`, `site.webmanifest`) and are linked from `admin/partials/favicons`, included by the layout and the sign-in page. Full logo (icon + wordmark) for use outside the panel: `public/logo.svg` / `logo.png` (light backgrounds) and `public/logo-dark.svg` / `logo-dark.png` (dark backgrounds); the SVGs use system fonts, so use the PNGs where exact lettering matters.

**Layout:** `resources/views/layouts/admin.blade.php`. Sidebar navigation (Overview / Content / Subscribers), flash messages and validation errors rendered once for every page (`admin/partials/flash`), a language menu with flags (English / Macedonian, see [localization.md](localization.md)) and a light / dark / system switch ([theme.md](theme.md)) above the account footer, and a slide-out menu below 860 px. Styling lives in `public/css/admin.css` (see [architecture.md](../architecture.md#ui-conventions)). The theme follows the OS setting unless the admin picks light or dark.

Shared behaviours (in the layout script):
- `<form data-confirm="…">` asks for confirmation before submitting (all deletes).
- `<button data-copy="#input">` copies an input's value.

## Dashboard (`admin.dashboard`)
- Stats: active subscribers / total, enabled channels / total, **watching now** (sessions active in the last 5 min), plays in 24 h.
- A "Get started" hint while there are no sources.
- Recent activity (8 latest plays), source list with sync status, subscriptions expiring within 7 days.

## Sources (`admin.sources.*`, `POST sources/{source}/sync`)
List with type/host, status badge (Not synced / Queued / Syncing / Synced / Failed with the error), channel counts (linked to the filtered channel list), bouquet, last sync, and Sync / Edit / Delete. See [sources.md](sources.md).

## Channels (`admin.channels.*`, `POST channels/bulk`)
- 50 per page, sorted by name, with logo, upstream host, category, source, bouquet count and status.
- **Filters** (GET, kept in pagination links): search by name, category (or *Uncategorized*), source (or *Added manually*), bouquet, status.
- **Bulk actions:** tick rows (or the header box for the whole page). Once a full page is selected, *Select all N matching* applies the action to every channel matching the current filters. Actions: enable, disable, add to bouquet, remove from bouquet, delete. Adding skips channels already in the bouquet.
- Create/edit form: name, stream URL, optional category and logo, enabled toggle (hidden `0` fallback). Editing an imported channel shows a notice that the next sync overwrites name/URL/logo/category but keeps enabled/disabled.

## Categories (`admin.categories.*`, only index/store/update/destroy)
Table with channel and enabled counts, *View channels* (filtered list), rename and create in dialogs, delete (channels keep existing without a category).

## Bouquets (`admin.bouquets.*`)
- List with channel count (and enabled count when different), user count, and a link to the bouquet's channels.
- Create/edit: name plus **add channels by category** (filterable tiles with channel counts). Ticked categories' channels are added; nothing is removed. Individual channels are managed with Channels-page bulk actions.

## Users (`admin.users.*`, `POST users/{user}/toggle`, `POST users/{user}/playlist-token`)
- List: search by username, status filter (active / expired / disabled), bouquets, expiry, max streams, and quick actions (playlist, enable/disable, edit, delete; you can't delete yourself).
- Form: username, password (text field, min 6, blank on edit = keep), role (subscriber / admin), status, expiry date with +1 month / +3 months / +1 year / Never shortcuts, simultaneous streams, bouquets (tiles with channel counts). Unticking all bouquets clears them.
- **User page** (`admin.users.show`; create and update redirect here):
  - **Playlist link** `/playlist/{token}.m3u` with copy button and *New link* (regenerates the token; the old link stops working).
  - Warnings when the panel is opened on localhost (link unreachable from a TV), when the account is disabled/expired, or when the playlist would be empty.
  - TV setup steps (TiviMate, IPTV Smarters, OTT Navigator, VLC) and the username/password URL alternative.
  - Subscription details (expiry, active/max streams, channel count, bouquets) and the 10 latest plays.

## Activity (`admin.logs.index`)
Stream starts, newest first, 50 per page, filterable by user (`?user=`), with IP and player user agent.
