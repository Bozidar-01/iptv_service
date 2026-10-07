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
| `relay/nebotv-relay.py` | Runs on the receiver (Python 3 standard library, nothing to install). `GET /s/<ref>.ts` streams a channel for a valid signed link. `/playlist.m3u?key=` returns the chosen bouquets as M3U, with markers inside a bouquet becoming `group-title`. `/status?key=` returns `{"ok", "box", "active_channels", "max_streams"}`. `/picon/*.png` serves logos. It can also keep a DuckDNS name updated every 5 minutes. |
| Source type **Satellite receiver** | Relay address + shared secret (stored encrypted, `sources.relay_secret`) + **Smaller streams (transcoding)** (`sources.transcode`). Imports `<relay>/playlist.m3u?key=<secret>` like any other source. Stream checking is always off for it, since checking tunes the receiver. |
| `ReceiverService::playUrl()` | Used by `/play`, Xtream `/live` and the admin preview. For receiver channels, appends `e` (expiry: now + `RECEIVER_LINK_MINUTES`, default 180), `x` (transcode) and `s` = first 32 hex characters of `HMAC-SHA256(secret, "<ref>|<e>|<x>")`. The format is shared with `sign()` in the relay; a test in each checks the same vector. |
| `iptv:check-receivers` (every 5 min) | Calls `/status`. Saves `receiver_online` / `receiver_checked_at`, shown on the Sources page. Creates a `receiver_down` / `receiver_up` alert on changes (panel and email). Always on, no watching needed. |

**Limits:**
- **At most `max_streams` different channels at once** (2 tuners). More viewers of a channel that's already playing share its tuner. A request for another channel gets `503 All tuners are busy`.
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

### After an OpenATV image update
Flashing wipes `/usr/bin` and `/etc/init.d`. Add the three files to the image backup (Setup → Software management → Backup settings → Select files), or repeat step 4.

## Checking and debugging
- Relay log: `/tmp/nebotv-relay.log` (cleared on reboot).
- `502 Receiver refused the stream (HTTP 401)`: wrong `box_password`.
- `503 All tuners are busy`: both tuners are on other channels (including recordings or your own TV).
- The Sources page shows *receiver online / offline*. An alert arrives when it changes.
- Relay tests: `python3 relay/test_relay.py`.
