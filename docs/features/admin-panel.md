# Admin Panel

Layout: `resources/views/layouts/admin.blade.php`, a sidebar with navigation and the logged-in user, styled by `public/css/admin.css`.

## Dashboard — `admin.dashboard` ("Network Health")
`DashboardController@index` shows:
- subscriber count (`role = user`), active channels, bouquets, stream starts in the last 24h
- the 10 most recent stream logs (user, channel, time)

The trend texts on the tiles (e.g. "+2.4% vs last week") are hard-coded placeholders.

## Users — `admin.users.*` ("User Accounts")
- Paginated list (20/page) with bouquets.
- Create/edit fields: `username` (unique), `password` (min 6; optional on edit, keeping the old one if blank), `status` (`active|disabled`), `role` (`admin|user`), `expiration_date` (nullable), `max_connections` (≥1), `bouquets[]`.
- Bouquets are synced on save.
- **Toggle** (`POST users/{user}/toggle`) flips status between active and disabled.
- Delete cascades tokens, sessions, logs and bouquet links.

## Channels — `admin.channels.*` ("Stream Channels")
- Paginated list (20/page) with category and Streaming/Offline chip.
- Fields: `name`, `stream_url` (url), `logo_url` (url, optional), `category_id` (required), `is_active` (checkbox with a hidden `0` fallback, so unchecking deactivates).
- Delete cascades pivot rows, sessions and logs.

## Categories — `admin.categories.*` ("Content Groups")
- Single page: list plus inline create/edit/delete. `name` is unique.
- Deleting a category sets its channels' `category_id` to null (they show as "General" in M3U).

## Bouquets — `admin.bouquets.*` ("Subscription Packs")
- List with channel and user counts.
- Fields: `name` (unique), `channels[]` (only active channels are offered in the picker).
- Channels are synced on save.

## Validation errors
All create/edit forms and the categories page show validation errors. Channel and user forms have their own inline block; bouquet forms and categories use `@include('admin.partials.errors')`.

## Activity Logs — `admin.logs.index`
- `stream_logs`, newest first, 50/page, with user and channel.
