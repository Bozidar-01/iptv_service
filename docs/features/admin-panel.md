# Admin Panel

**Layout:** `resources/views/layouts/admin.blade.php`. Sidebar navigation (Overview / Content / Subscribers), flash messages and validation errors rendered once for every page (`admin/partials/flash`), and a slide-out menu below 860 px. Styling lives in `public/css/admin.css` (see [architecture.md](../architecture.md#ui-conventions)). Light and dark themes follow the OS setting.

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
