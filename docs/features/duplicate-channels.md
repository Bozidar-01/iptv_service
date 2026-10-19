# Duplicate channels

The same channel is often listed more than once: by two providers, by a provider and the satellite receiver, or twice by one source. **Channels → Find duplicates** (`admin.channels.duplicates`, admins only; `ChannelDuplicateService`) shows each group of copies, suggests the best one and switches the others off.

## What counts as the same channel

Two **enabled** channels are copies when
- their names match after ignoring case, a leading country code (`IT:`, `|IT|`, `[DE]`), quality tags (`HD`, `FHD`, `SD`, `4K`, `UHD`, `1080p`, `720p`, `HEVC`…) and punctuation (`+` and `&` are kept as "plus" and "and": *Sky Sports +1* is not *Sky Sports 1*), or
- they share a guide (tvg) id (empty ids and `none`, `n/a`, `0`… are ignored).

Both rules join groups (a name match and a guide-id match together make one group). A group is left out when **none** of its copies is in a bouquet (nobody gets it anyway) or when it has **more than 12 copies** (a generic name or guide id is joining unrelated channels; look at those by hand).

## The suggested copy

In this order: it **works** (stream check says online, then never checked, then not working), the best **quality**: the picture size the **stream check measured** (it reads the highest `RESOLUTION` and `BANDWIDTH` from an HLS master playlist while it checks channels: 2000+ px = 4K, 1000+ = Full HD, 700+ = HD, less = SD, saved in `channels.quality_height` / `quality_kbps`), else the tag in the name (4K, Full HD, HD, no tag, SD); then a higher measured bitrate (in 0.5 Mbps steps). Only HLS channels with a master playlist are measured (not raw TS streams or receiver channels), so run *Check streams* to get real numbers. The page shows a *Quality* column: a green badge such as `1080p · 4.5 Mbps` when measured, grey text when guessed, **not a satellite receiver** (one tuner serves only a few streams), in the most **bouquets**, checked most recently, then the oldest. Run *Check streams* first so "works" is current.

## Switching off

Per page you choose which copy to keep in each group (or *Leave all as they are*) and press *Switch off the others on this page*; or *Keep the suggested copy in all N groups* applies the suggestion to every group (limited to the search when there is one). For every group:
- the kept channel is **added to the bouquets** of the copies being switched off, so nobody loses the channel;
- the other copies are **disabled** (`is_active = false`), **not deleted**: a sync never turns a disabled channel back on (it would import a deleted one again), and you can enable one again on the Channels page;
- groups that changed since the page was shown, or a chosen channel that is not in its group, are skipped and the message says how many.

The action is written to the audit log (*Duplicate channels switched off*, groups and channels).

## Notes

- Hidden-channel lists ([hide-channels.md](hide-channels.md)) refer to channels, so a user who had a switched-off copy hidden gets the kept copy.
- A switched-off copy no longer acts as an automatic backup (backups must be enabled): if you want a fallback for a channel, leave that group alone and set a backup channel on the channel instead.
- The scan reads enabled channels in chunks of 5,000 with a few columns; about 5,600 channels take roughly 0.1 s, so it runs on every visit.
