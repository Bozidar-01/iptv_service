# Satellite receiver (NeboTV relay)

Lets viewers outside your home watch channels from an Enigma2 satellite receiver, such as the **Octagon SF8008 with OpenATV**, while the panel runs on Hostinger. The receiver itself is never exposed to the internet.

```
TV app ──► NeboTV panel (/play or Xtream /live)
             checks login, subscription, stream limit; logs the play
             └─► 302 to http://<name>.duckdns.org:28080/s/<channel>.ts?e=<expiry>&x=<0|1>&s=<signature>
TV app ──► router (port 28080 forwarded) ──► relay on the receiver (relay/nebotv-relay.py)
             checks signature + expiry, max channels = tuners
             └─► streams from 127.0.0.1:8001 (or :8002 transcoded) with the box's stream login
```

**Only share free-to-air channels or channels you are entitled to share.** The relay offers only the bouquets listed in its settings, and with `fta_only = yes` (the default) it leaves out every channel the receiver's channel database (`/etc/enigma2/lamedb`) marks as encrypted. Never list card-sharing bouquets (icam / streamrelay / EMU).

## How it works

| Part | What it does |
|---|---|
| `relay/nebotv-relay.py` | Runs on the receiver (Python 3 standard library, nothing to install). `GET /s/<ref>.ts` streams a channel for a valid signed link. `/playlist.m3u?key=` returns the chosen bouquets as M3U, with markers inside a bouquet becoming `group-title`. `/status?key=` returns `{"ok", "box", "version", "active_channels", "active_tuners", "max_streams", "single_cable", "satellites"}`. `/picon/*.png` serves logos. It can also keep a DuckDNS name updated every 5 minutes. |
| Source type **Satellite receiver** | Relay address + shared secret (stored encrypted, `sources.relay_secret`) + **Smaller streams (transcoding)** (`sources.transcode`). Imports `<relay>/playlist.m3u?key=<secret>` like any other source. Stream checking is always off for it, since checking tunes the receiver. |
| `ReceiverService::playUrl()` | Used by `/play`, Xtream `/live` and the admin preview. For receiver channels, appends `e` (expiry: now + `RECEIVER_LINK_MINUTES`, default 180), `x` (transcode) and `s` = first 32 hex characters of `HMAC-SHA256(secret, "<ref>|<e>|<x>")`. The format is shared with `sign()` in the relay; a test in each checks the same vector. |
| `iptv:check-receivers` (every 5 min) | Calls `/status`. Saves `receiver_online` / `receiver_checked_at`, shown on the Sources page. Creates a `receiver_down` / `receiver_up` alert on changes (panel and email). Always on, no watching needed. |

**Limits:**
- **At most `max_streams` different transponders at once** (2 tuners). A transponder is one frequency that carries a bundle of channels (about 6 per transponder on the Octagon source: 2,001 channels on 306 transponders). **Channels on the same transponder share one tuner**, so many channels of a bundle can play together, and any number of viewers can watch the same channel. The transponder comes from the service reference (`1:0:19:D49:1450:13E:820000:…` → transponder id `1450`, network `13E`, namespace `820000`).
- A request that needs a tuner that isn't there gets `503 All tuners are busy` **at once**, with `Retry-After: 10`.
- **`single_cable = yes`** (both tuners on one dish cable and one DiSEqC switch): the receiver can only be on one satellite at a time, so a channel from another satellite is refused at once with `503 The receiver is on another satellite right now` while anything else is playing. The satellite is the top half of the namespace (`820000` = 13.0 E, `C00000` = 19.2 E, `A00000` = 16.0 E). Terrestrial, cable and stream references have none and are never refused for it. Set it when a second tuner can't reach the satellite the first one is on (the 503 appears after a few seconds of no video otherwise).
- **No video:** when the receiver accepts a stream but sends no data (its tuner can't lock: busy on another satellite, no signal, encrypted), the relay logs `No video from the receiver for <ref>`.
- **Upload bandwidth:** a raw HD channel uses about 8–15 Mbps, a transcoded one about 1 Mbps (720p).
- **A signed link works for 3 hours** (`RECEIVER_LINK_MINUTES`), so players can reconnect after a drop. Someone who copies a link can use it until then.
- **The relay speaks plain HTTP.** No passwords travel in its links, only signatures.

## Setup

### 1. Router
- Give the receiver a fixed address: a DHCP reservation for `192.168.100.40`, or a static IP on the box.
- Forward **TCP 28080** to `192.168.100.40:28080` (NAT / Port forwarding / Virtual server, on the INTERNET connection). Don't forward 80 or 8001.

### 2. DuckDNS (your home IP changes)
Sign in at duckdns.org, add a name (e.g. `nebotv-home`) and copy the token. The relay keeps `nebotv-home.duckdns.org` pointed at your home.

### 3. Panel
Sources → **Add source** → **Satellite receiver**:
- Relay address `http://nebotv-home.duckdns.org:28080`.
- Copy the **Shared secret** shown.
- Optional: **Smaller streams**.
- Pick a bouquet.

The first import fails until the relay runs. That's expected; use **Sync** after step 5.

### 4. Receiver
Copy three files to the box with FTP (FileZilla, user `root`) or from a computer with the repo:

```bash
scp relay/nebotv-relay.py root@192.168.100.40:/usr/bin/nebotv-relay.py
scp relay/nebotv-relay.init root@192.168.100.40:/etc/init.d/nebotv-relay
scp relay/nebotv-relay.conf.example root@192.168.100.40:/etc/nebotv-relay.conf
```

Then on the box (`ssh root@192.168.100.40`):

```bash
chmod +x /usr/bin/nebotv-relay.py /etc/init.d/nebotv-relay
vi /etc/nebotv-relay.conf        # secret, box_password, bouquets, public_url, [duckdns] domain + token
python3 /usr/bin/nebotv-relay.py --playlist | head -20   # check the channel list
update-rc.d nebotv-relay defaults
/etc/init.d/nebotv-relay start
tail -f /tmp/nebotv-relay.log
```

`box_password` is the box's root password: OpenWebif has "authentication for streaming" on. `bouquets` takes names exactly as on the receiver, one per line, e.g. `• 13,0 E - RAI • Mediaset • TivùSat`.

### 5. Test from outside
On a phone with Wi‑Fi off, open `http://nebotv-home.duckdns.org:28080/status?key=<secret>`. It should show `"ok": true`. Then **Sync** the source in the panel and give users its bouquet.

### 6. Optional: transcoding
Turn on transcoding on the receiver (OpenATV: Setup → System → Transcoding, 720p / 1 Mbps). Port 8002 then answers, and the **Smaller streams** switch works.

### Receiver page in the panel (`admin.receiver.show`)
Sources → *Receiver* on a receiver source (admins). It reads the relay's `/status` (kept 15 s; *Refresh* clears it): online or not, relay version, **tuners in use / max**, the **satellite it is on now** (`13.0° E`), whether `single_cable` is on, and **Latest problems**: the relay's last 20 refusals (`All tuners are busy`, `another satellite`) and streams with **no video**, with channel names and times. Relay 1.2+ reports problems (an older one shows a hint to update it). Errors say whether the relay did not answer, refused the secret, or the box does not answer.

**Shorten the channel list** (same page): switch off *Radio and data services* (service type radio/data or `(RADIO)` names), *Internal stream entries* (`http%3a` references: they only play if the receiver runs that local stream service, try one first) and/or *Channels without a TV guide*, each with its count. Receiver channels can't be stream-checked (a check would take a tuner), so this uses the reference and the guide. Nothing is deleted: switched-off channels stay off after syncs and can be enabled again. Audit-logged.

### TV guide from the receiver
**Coverage** on the Receiver page: how many of its (listed) channels have a TV guide from any guide, with a link to Channels → status *No TV guide* for that source. When the relay reports `guide = no` the card says so and offers no import.

Relay **1.5** serves `GET /epg.xml?key=<secret>`: the receiver's own guide for the configured bouquets, as gzipped XMLTV (next 3 days, channel ids = service references like the playlist's `tvg-id`, only channels it has programmes for; the channel list and the programmes are two gzip members of one file, which the panel reads as one). It is read in the background 5 minutes after the relay starts and every 6 hours, **one channel at a time** with OpenWebif's `/api/epgservice` (each channel once, however many bouquets it is in; encrypted ones left out with `fta_only`), 0.1 s apart, and compressed as it is read. It stops early when the box has less than 100 MB free (`/proc/meminfo`). `/status` reports `guide` (channels, programmes, compressed size, when, complete or not). Until the first read is done it answers `503` with `Retry-After`; with `guide = no` in the settings it answers `404` and reads nothing.

Never ask OpenWebif for a whole bouquet's guide at once (`/api/epgmulti`): it ignores the end time, so a big bouquet answers with every programme it has (13 MB for 110 channels on the Octagon), and on a box with about 240 MB free that crashed the relay and once the receiver's own program (Enigma2 restarted by itself). On the Octagon: 3,521 channels in 274 bouquets, about 0.2 s each, so about 12 minutes. On the Receiver page, **Use the receiver's TV guide** (`POST admin/sources/{source}/receiver/guide`) adds it once as a guide source (`epg_sources.receiver_source_id`; the key is added from the receiver source only when downloading, never stored in the guide's address or its errors) and imports it; it is then imported every night with the other guides. A relay older than 1.5 shows a hint to update it. Enigma2 only keeps programmes of channels it has tuned: an EPG import plugin, or the nightly EPG refresh in the receiver's settings, fills it for all channels. Receiver channels the box has no programmes for keep matching other guides by name.

### Updating the relay on the receiver
Copy the new program and start script over the old ones and restart it (your settings file stays as it is):

```bash
scp relay/nebotv-relay.py root@192.168.100.40:/usr/bin/nebotv-relay.py
scp relay/nebotv-relay.init root@192.168.100.40:/etc/init.d/nebotv-relay
ssh root@192.168.100.40 'chmod +x /etc/init.d/nebotv-relay; /etc/init.d/nebotv-relay restart && tail -n 3 /tmp/nebotv-relay.log'
```

The log line shows the version (`NeboTV relay 1.5`) and `(one cable: one satellite at a time)` when `single_cable = yes` is set. To use the new setting, add `single_cable = yes` under `[relay]` in `/etc/nebotv-relay.conf` first (see `relay/nebotv-relay.conf.example`).

### Slow bouquets
**Set `bouquets` to the ones you offer.** With `bouquets =` empty the relay reads every bouquet on the box (274 on the Octagon, about 3,500 channels, many of them technical lists and radio) for each sync: slow, and on a box with about 200 MB free it was killed for using too much memory (exit code 137) while building the playlist. Print the names with `wget -qO- http://127.0.0.1/api/bouquets` on the box.

With many bouquets (274 on the Octagon) the box sometimes takes more than 10 s to list one. Relay 1.6 waits up to 60 s and tries twice (`bouquet_services`). A bouquet that still doesn't answer gets its channel list from the last time it was read, so its channels don't disappear from the panel (an import deletes channels missing from the playlist); only a bouquet never read since the relay started makes the playlist fail (`502`, the panel keeps its channels). The log says `Bouquet … did not answer (…): using its last channel list`.

### Standby: when the relay or the receiver stops
- **The relay restarts itself.** The start script (`relay/nebotv-relay.init`) runs the relay in a loop: when it stops for any reason it writes `WARNING Relay stopped (exit code N, …)` to the log (137 = killed, usually out of memory) and starts it again after 10 s. The log, which lives in memory, is cut back to its last 500 lines past 1 MB. `/etc/init.d/nebotv-relay status` says whether the relay itself runs. `stop` and `status` match only the relay's own process (command line ending in `/usr/bin/nebotv-relay.py`): never stop it with `pkill -f nebotv-relay.py`, which also matches the restart loop and the shell running the command. A box that reboots starts it at boot (`update-rc.d`).
- **Provider copies stand in for receiver channels.** Channels → Duplicates → *Only with a receiver channel* lists the receiver channels that a provider playlist also has (matched by name). Keeping the suggested copy (usually the provider's: a tuner serves only a few streams) with *Keep the others as hidden backups* makes the receiver channel its hidden backup, and the other way round. When one stops working, viewers get the other ([channel-health.md](channel-health.md)).
- Not covered: the home internet or power going down takes all receiver channels with it; a UPS for the box and the router helps against short power cuts.

### After an OpenATV image update
Flashing wipes `/usr/bin` and `/etc/init.d`. Add the three files to the image backup (Setup → Software management → Backup settings → Select files), or repeat step 4.

## Checking and debugging
- Relay log: `/tmp/nebotv-relay.log` (cleared on reboot).
- `502 Receiver refused the stream (HTTP 401)`: wrong `box_password`.
- `503 All tuners are busy`: both tuners are on other transponders (including recordings or your own TV).
- `503 The receiver is on another satellite right now`: `single_cable = yes` and something is playing from a different satellite.
- The Receiver page shows the same problems without SSH.
- `No video from the receiver` in the log, and the player shows nothing after a few seconds: the receiver accepted the stream but its tuner couldn't lock. Test on the box: `wget -S -q -O - -T 10 "http://root:PASSWORD@127.0.0.1:8001/<service reference>" | head -c 200000 | wc -c` (type the real password; a number like 200000 means it streams, 0 means it can't tune: no free tuner for that satellite, no signal, or encrypted).
- The Sources page shows *receiver online / offline*. An alert arrives when it changes.
- Relay tests: `python3 relay/test_relay.py`.
