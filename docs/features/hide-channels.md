# Hide channels (per user, and for all of an owner's users)

A bouquet is shared by many users and resellers, so nobody removes a channel from it to keep it from one customer. Instead a channel can be **hidden**: it stays in the bouquet, but the person it is hidden for no longer gets it. Admins and resellers can do this; the code is `ChannelHideService`, `HiddenChannelController` and `Channel::scopeAvailableTo`.

## Two lists

| List | Table | Hides the channel for | Edited by |
|---|---|---|---|
| **Per user** | `user_hidden_channels` (`user_id`, `channel_id`) | one user | the reseller who owns the user, or an admin |
| **All my users** | `owner_hidden_channels` (`owner_id`, `channel_id`) | every user of one owner: a reseller's customers, or with `owner_id` null the admins' own users (users without a reseller) | that reseller; any admin edits the shared "admins' users" list |

A user gets a channel when it is enabled, is in one of their bouquets, and is in **neither** list. `Channel::scopeAvailableTo()` applies both lists, so the playlist, the Xtream API (live list, categories, guide), the TV guide and playback (`StreamService::isAllowed`, also for proxied streams) all follow; so does the channel count on the user page and on the subscriber's own page. A hidden channel answers **403** if someone plays its address anyway.

## Pages

- **Hide channels** (`admin.hide-channels.index`, menu: Subscribers): the channels of the bouquets the person holds (a reseller: their own bouquets; an admin: all), with search, category, bouquet and *Shown / Hidden* filters, 50 per page. Select channels (or *Select all N matching*), then *Hide for all my users* or *Show again for my users*. The header shows *N of M channels hidden*.
- **Channels of a user** (`admin.users.channels`, button *Choose channels* and the *Hide some* link on the user page; subscribers only): the channels in that user's bouquets, with the same filters and bulk actions, *Hide for NAME* / *Show again for NAME*. Each row says why a channel is hidden: for this user, or for all users of their owner (an admin sees whose list).

- **By category** (a fold-out panel on both pages): every category with its channel count and how many are hidden, and *Hide all* / *Show all* for the whole category (including "Uncategorized"), in one click.

## Rules

- A **reseller** only works with the bouquets they hold. Channels from bouquets the admin added to a customer are not shown to the reseller and can't be hidden by them; ids sent from elsewhere are ignored (`HiddenChannelController::apply` always starts from the channels the person may work with). Someone else's user is a **404**, like everywhere else.
- Hiding does **not** change bouquets, so other resellers and users are unaffected. The admins' own list only affects users without a reseller; a reseller's list only affects that reseller's customers.
- Bulk actions are set-based (`insertUsing`, one `DELETE`), so hiding a whole bouquet of tens of thousands of channels is a couple of queries. Hiding a channel that is already hidden is skipped (the message says how many were newly hidden).
- Hides are deleted with the user, the reseller or the channel. A hide stays when a channel leaves a user's bouquets and applies again if it returns.
- Nothing is hidden by default: existing users get exactly what they got before.
