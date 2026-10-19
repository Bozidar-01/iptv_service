# Favourites

A viewer can pick favourite channels on their personal page (`/me/{playlist token}` → **My favourites**): search a channel (2+ letters, among the channels they can watch), **Add**, move **up / down**, **remove**; up to 100 (`FavoriteService::MAX`). No sign-in: the link is the secret, like the rest of that page; the routes have no session, so a bad request is refused with 422. The page doesn't show the box for an expired or disabled user.

**In apps** (after the viewer reloads the playlist):
- **M3U** (`/playlist/{token}.m3u`, `/m3u`, `/get.php`): the favourites come first, in the viewer's order, in a group **Favourites**, then every channel as before. So a favourite appears twice (in Favourites and in its own category).
- **Xtream** (`player_api.php`): `get_live_categories` has **Favourites** (`category_id` `-1`) as the first category when the viewer has any; `get_live_streams&category_id=-1` lists them in order; the full list (no category) starts with them as category `-1`, then every channel in its own category. A real category lists only its own channels.
- A channel that is switched off, hidden for the user or out of their bouquets drops out of their favourites by itself and returns when it is available again. Favourites are deleted with the user or the channel (`user_favorites`).
