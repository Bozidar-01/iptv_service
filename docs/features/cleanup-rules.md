# Cleanup rules

Names and categories come from providers and the satellite receiver as they are: `24h HD Canarias [301]`, groups like `(TV) 11222 H 30000 3/4 2025 - 2060`. **Cleanup rules** (admin → Content → *Cleanup rules*, admins only; `CleanupRuleService`) tidy them the same way on every import.

## Rules (`cleanup_rules`)

| Kind | What it does |
|---|---|
| **Change channel names** (`channel_name`) | `contains`: removes or replaces the text (case-insensitive); `is exactly`: renames a channel with that name; `regex`: `preg_replace` (use `$1` for groups). A rule can never make a name empty. |
| **Rename or merge categories** (`category`) | Works on the playlist's group name. `contains` / `is exactly`: the whole category becomes the replacement (so several groups merge into one); `regex`: `preg_replace`. |
| **Put channels in a category by name** (`channel_category`) | A channel whose (cleaned) name matches goes into the category in *It becomes*. Optionally only for channels that are in a given category (`when_category`): e.g. only in *Satellite*. The first matching rule wins. |

Each rule can be limited to one source, switched off, and deleted. Rules run in the order they were added. A regex is checked when you save it (*This is not a valid pattern.*).

## Where they apply

- **On every import** (`SourceSyncService`): name, then category rules, then channel-by-name rules, all before saving. The channel's identity key (`external_key`) still comes from the **raw** playlist entry, so adding or changing a rule never makes duplicates or loses a channel's bouquets and favourites.
- **On demand:** *Apply now* runs the enabled rules over the channels already stored (set-based: one `UPDATE` per 1,000 changed channels per column), then deletes categories that lost all their channels. Running it twice changes nothing the second time. It is written to the audit log.
- **Preview:** *Preview* on the add form shows how many names and categories a new rule would change and a few examples, without saving anything.

Names the admin edits by hand are overwritten by the next import (as before); a rule is how to make a change stick.

## Ready-made rules

One click adds a set (already existing rules are skipped):
- **Remove tags in square brackets:** `24h HD Canarias [301]` → `24h HD Canarias`.
- **Radio channels:** removes `(RADIO)` from names; names starting with `(RADIO)` or `Radio` go to *Radio*.
- **Satellite group names:** `(TV) 11222 H 30000 3/4 …` and similar → *Satellite*.
- **Genres by channel name:** only for channels in *Satellite*: Sports, News, Kids, Movies, Music, Documentary from words like Sky Sport, CNN, Disney, Cinema, MTV, Discovery. Other sources already come sorted (by country or genre) and are left alone. Add *Satellite group names* with it.

Measured on the dev data (5,637 channels, all four sets): 570 names and 283 categories would change.
