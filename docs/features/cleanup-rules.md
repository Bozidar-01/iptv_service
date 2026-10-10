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

## Organize by country (`CountryOrganizeService`)

A card at the top of the page puts **every channel in a category named after its country** ("Italy", "Serbia", "Germany"), so apps show a tidy list instead of a mix of genres, packages and satellite groups.

**Where the country comes from**, in this order: (1) the playlist id (`Name.de@SD` → Germany; `uk` = United Kingdom), (2) the group name when it is a country ("Italy", "UK", "VOD Italy", `<flag> / Japan`), (3) a tag at the start of the name (`IT:`, `|DE|`, `[RS]`, `(US)`, `MK -`, and the local `BH:` = Bosnia and Herzegovina), (4) the name of the list it came from (ITALIJA, SRBIJA, AMERIKA, Macedonia – TV), (5) the country of the **TV guide** it is matched to (guide files are per country), (6) words in the group or name (ORF, WDR, Freesat Romania, RAI, Hrvatska, Pink…). What cannot be placed goes to **International**. **Radio** (service type, `(RADIO)` names, groups called Radio) and **Adult** (erotic, xxx, babes…) are their own categories, so adult can be hidden in one click on Hide channels → By category.

**Genre split:** a country with more than *Split big countries by genre above* (default 120) **enabled** channels gets "Italy – News", "Italy – Sports", "Italy – Movies", "Italy – Kids", "Italy – Music", "Italy – Documentary"; a genre needs at least *A genre needs at least* (default 12) channels, the rest stay in plain "Italy". Genre comes from the iptv-org list the channel came from, the group name, or words in the name.

**Using it:** *Preview* shows how many enabled channels land in which category and examples that could not be placed (nothing changes). *Save* stores the settings, *Keep it on* makes **every import** follow it (after the import and the guide match, `SourceSyncService` re-runs it for that source), *Save and apply now* moves all channels now (`Apply now` above does it too when it is on). *Leave these sources as they are* keeps chosen sources untouched. It is repeatable (a second run changes nothing), set-based (one UPDATE per 1,000 changed channels, about 0.5 s for 12,000 channels) and deletes categories that end up empty. Switching it off stops it running on imports; channels keep the categories they have. The settings are one `cleanup_rules` row of kind `country` (JSON in `replacement`), not shown in the rules table.

Tried on a copy of the production database (12,038 channels, 5,120 enabled): 457 categories became 183, 142 country groups for the enabled channels, about 160 enabled channels in International. Satellite channels whose group is a package for several countries (for example one named after a satellite position) can land in the wrong country: exclude the Octagon source or fix such a channel by hand if it matters.

## Ready-made rules

One click adds a set (already existing rules are skipped):
- **Remove tags in square brackets:** `24h HD Canarias [301]` → `24h HD Canarias`.
- **Radio channels:** removes `(RADIO)` from names; names starting with `(RADIO)` or `Radio` go to *Radio*.
- **Satellite group names:** `(TV) 11222 H 30000 3/4 …` and similar → *Satellite*.
- **Genres by channel name:** only for channels in *Satellite*: Sports, News, Kids, Movies, Music, Documentary from words like Sky Sport, CNN, Disney, Cinema, MTV, Discovery. Other sources already come sorted (by country or genre) and are left alone. Add *Satellite group names* with it.

Measured on the dev data (5,637 channels, all four sets): 570 names and 283 categories would change.
