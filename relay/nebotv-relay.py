#!/usr/bin/env python3
"""
NeboTV relay for Enigma2 receivers (e.g. Octagon SF8008 with OpenATV).

Runs on the receiver and lets viewers outside the home watch its channels without
exposing the receiver itself:

  GET /s/<channel>.ts?e=<expires>&x=<0|1>&s=<signature>
      Streams one channel from the receiver (port 8001, or 8002 transcoded when x=1).
      Only links signed by the NeboTV panel with the shared secret work, and only until
      they expire. Channels on the same transponder share a tuner; at most `max_streams`
      different transponders at once (= tuners). With `single_cable = yes` (both tuners on one dish
      cable) also only one satellite at a time. Anything else gets an immediate 503.
  GET /playlist.m3u?key=<secret>
      The channels of the configured bouquets, for the panel to import. Markers inside a
      bouquet become categories. With fta_only, encrypted channels are left out.
  GET /status?key=<secret>
      JSON health report for the panel.
  GET /epg.xml?key=<secret>
      The receiver's own TV guide for the configured bouquets, as XMLTV, for the panel to import (channel ids are the
      service references, like the playlist's tvg-id). Only channels the receiver has programmes for are listed. Read
      in the background 5 minutes after the start and every 6 hours, one channel at a time with a pause in between
      (asking for a whole bouquet at once can use more memory than the box has, and crash the receiver), and stopped
      early when the box runs low on memory. 503 + Retry-After until the first one is ready; 404 with guide = no.
  GET /picon/<file>.png
      Channel logos from the receiver.

Optionally keeps a DuckDNS name pointed at the current home IP.

Standard library only (Python 3.8+), so nothing needs installing on the receiver.
Settings: /etc/nebotv-relay.conf (see nebotv-relay.conf.example).
"""

import asyncio
import base64
import collections
import gzip
import configparser
import hashlib
import hmac
import json
import logging
import os
import re
import sys
import time
import urllib.parse
import urllib.request
import zlib
from xml.sax.saxutils import escape, quoteattr

VERSION = '1.6'
CONFIG_PATHS = [
    '/etc/nebotv-relay.conf',
    os.path.join(os.path.dirname(os.path.abspath(__file__)), 'nebotv-relay.conf'),
]
MAX_REQUEST_BYTES = 8192
CHUNK = 64 * 1024

log = logging.getLogger('nebotv-relay')


# ---------------------------------------------------------------- settings

class Settings:
    def __init__(self, path):
        parser = configparser.ConfigParser(interpolation=None)
        if not parser.read(path, encoding='utf-8'):
            raise SystemExit('Cannot read settings file %s' % path)
        relay = parser['relay'] if parser.has_section('relay') else {}
        get = lambda key, default='': (relay.get(key, default) or default).strip()

        self.port = int(get('port', '28080'))
        self.secret = get('secret')
        if len(self.secret) < 16:
            raise SystemExit('Set "secret" in %s (at least 16 characters, the same as in the NeboTV panel).' % path)
        self.max_streams = int(get('max_streams', '2'))
        # Both tuners fed by one dish cable (one DiSEqC switch): the receiver can be on one satellite at a time
        self.single_cable = get('single_cable', 'no').lower() in ('1', 'yes', 'true', 'on')
        self.box_host = get('box_host', '127.0.0.1')
        self.webif_port = int(get('webif_port', '80'))
        self.stream_port = int(get('stream_port', '8001'))
        self.transcode_port = int(get('transcode_port', '8002'))
        self.box_user = get('box_user', 'root')
        self.box_password = get('box_password')
        self.webif_user = get('webif_user')
        self.webif_password = get('webif_password')
        self.bouquets = [b.strip() for b in re.split(r'[\n;]', get('bouquets')) if b.strip()]
        self.fta_only = get('fta_only', 'yes').lower() in ('1', 'yes', 'true', 'on')
        # Read the receiver's TV guide for the panel (/epg.xml)
        self.guide = get('guide', 'yes').lower() in ('1', 'yes', 'true', 'on')
        self.lamedb = get('lamedb', '/etc/enigma2/lamedb')
        self.public_url = get('public_url').rstrip('/')

        duck = parser['duckdns'] if parser.has_section('duckdns') else {}
        self.duckdns_domain = (duck.get('domain', '') or '').strip().replace('.duckdns.org', '')
        self.duckdns_token = (duck.get('token', '') or '').strip()


def load_settings():
    path = os.environ.get('NEBOTV_RELAY_CONFIG') or next((p for p in CONFIG_PATHS if os.path.exists(p)), CONFIG_PATHS[0])
    return Settings(path)


# ---------------------------------------------------------------- signing (must match the panel's ReceiverService)

def sign(secret, channel, expires, transcode):
    payload = '%s|%s|%s' % (channel, expires, transcode)
    return hmac.new(secret.encode(), payload.encode(), hashlib.sha256).hexdigest()[:32]


def encode_ref(service_ref):
    return base64.urlsafe_b64encode(service_ref.encode()).decode().rstrip('=')


def decode_ref(encoded):
    try:
        ref = base64.urlsafe_b64decode(encoded + '=' * (-len(encoded) % 4)).decode()
    except Exception:
        return None
    # Enigma2 service reference: 1:0:19:D49:1450:13E:820000:0:0:0:
    return ref if re.fullmatch(r'[0-9A-Fa-f]+(:[0-9A-Fa-f]*){9,10}:?', ref) else None


# ---------------------------------------------------------------- receiver (OpenWebif)

def webif_get(settings, path, timeout=10):
    request = urllib.request.Request('http://%s:%d%s' % (settings.box_host, settings.webif_port, path))
    if settings.webif_user:
        token = base64.b64encode(('%s:%s' % (settings.webif_user, settings.webif_password)).encode()).decode()
        request.add_header('Authorization', 'Basic ' + token)
    with urllib.request.urlopen(request, timeout=timeout) as response:
        return response.read()


def encrypted_services(path):
    """Service keys (sid, tsid, onid, namespace) that have conditional-access IDs in lamedb."""
    keys = set()
    try:
        with open(path, encoding='utf-8', errors='replace') as handle:
            lines = handle.read().splitlines()
    except OSError:
        return None

    def add(ids, flags):
        parts = ids.split(':')
        if len(parts) >= 4 and re.search(r'(^|,)C:', flags):
            sid, ns, tsid, onid = (int(p, 16) for p in parts[:4])
            keys.add((sid, tsid, onid, ns))

    if lines and lines[0].startswith('eDVB services /5/'):  # lamedb5
        for line in lines:
            if line.startswith('s:'):
                head, _, rest = line[2:].partition(',')
                add(head, rest)
    else:  # lamedb version 4: "services" section, three lines per service
        try:
            start = lines.index('services') + 1
        except ValueError:
            return keys
        i = start
        while i + 2 < len(lines) and lines[i] != 'end':
            add(lines[i], lines[i + 2])
            i += 3
    return keys


def service_key(ref):
    parts = ref.split(':')
    sid, tsid, onid, ns = (int(p or '0', 16) for p in (parts[3], parts[4], parts[5], parts[6]))
    return (sid, tsid, onid, ns)


def transponder_key(ref):
    """(tsid, onid, namespace) of a service reference. Channels with the same one are on the same transponder
    (one frequency), so the receiver serves them all with one tuner."""
    parts = ref.split(':')
    try:
        return tuple(int(parts[i] or '0', 16) for i in (4, 5, 6))
    except (ValueError, IndexError):
        return None


def satellite_of(ref):
    """The satellite of a service reference: the orbital position in tenths of a degree, which is the top half of the
    namespace (0x820000 = 13.0 E). None for terrestrial (0xEEEE), cable (0xFFFF) and stream references (0)."""
    key = transponder_key(ref)
    high = key[2] >> 16 if key else 0
    return high if 0 < high < 3600 else None


def clean_name(name):
    name = re.sub(r'[•|:#«»®]+', ' ', name)
    return re.sub(r'\s+', ' ', name).strip(' -')


def selected_bouquets(settings):
    """[ref, name] of the bouquets in the settings (all of them when none are set)."""
    bouquets = json.loads(webif_get(settings, '/api/bouquets'))['bouquets']
    wanted = [b.lower() for b in settings.bouquets]
    wanted_clean = [clean_name(w).lower() for w in wanted]
    return [[ref, name] for ref, name in bouquets if not wanted or clean_name(name).lower() in wanted_clean or name.lower() in wanted]


BOUQUET_TIMEOUT = 60
BOUQUET_TRIES = 2
# Last good channel list of each bouquet: a bouquet that doesn't answer in time gets these, so its channels don't vanish
# from the panel (an import deletes channels missing from the playlist). Kept until the relay restarts.
_bouquet_cache = {}


def bouquet_services(settings, ref):
    """The services of one bouquet (with picons). A slow box gets BOUQUET_TRIES tries of BOUQUET_TIMEOUT seconds; then the
    last good answer is used, and only a bouquet never read before fails the request."""
    error = None
    for _ in range(BOUQUET_TRIES):
        try:
            services = json.loads(webif_get(settings, '/api/getservices?' + urllib.parse.urlencode({'sRef': ref, 'picon': 1}), timeout=BOUQUET_TIMEOUT))['services']
            _bouquet_cache[ref] = services
            return services
        except Exception as found:
            error = found
    if ref in _bouquet_cache:
        log.warning('Bouquet %s did not answer (%s): using its last channel list', ref, error)
        return _bouquet_cache[ref]
    raise error


def build_playlist(settings, base_url):
    encrypted = encrypted_services(settings.lamedb) if settings.fta_only else set()
    if encrypted is None:
        log.warning('Cannot read %s: encrypted channels cannot be left out', settings.lamedb)
        encrypted = set()

    lines = ['#EXTM3U']
    count = 0
    for ref, name in selected_bouquets(settings):
        group = clean_name(name)
        services = bouquet_services(settings, ref)
        for service in services:
            sref = service.get('servicereference', '')
            title = (service.get('servicename') or '').strip()
            if sref.startswith('1:64:'):  # marker: following channels belong to it
                group = clean_name(title) or group
                continue
            if not sref.startswith('1:0:') or not title:
                continue
            if service_key(sref) in encrypted:
                continue
            logo = service.get('picon') or ''
            logo_attr = ' tvg-logo="%s%s"' % (base_url, logo) if logo.startswith('/picon/') else ''
            lines.append('#EXTINF:-1 tvg-id="%s"%s group-title="%s",%s' % (sref, logo_attr, group.replace('"', "'"), title))
            lines.append('%s/s/%s.ts' % (base_url, encode_ref(sref)))
            count += 1
    log.info('Playlist: %d channels', count)
    return '\n'.join(lines) + '\n'


EPG_DAYS = 3
EPG_REFRESH_SECONDS = 6 * 3600
EPG_FIRST_DELAY = 300
# Pause between two channels, so the receiver stays responsive
EPG_PAUSE = 0.1
# Stop reading the guide when the box has less free memory than this (MB)
EPG_MIN_FREE_MB = 100
EPG_MAX_DESCRIPTION = 400


def xmltv_time(timestamp):
    return time.strftime('%Y%m%d%H%M%S +0000', time.gmtime(timestamp))


def free_memory_mb():
    """MemAvailable from /proc/meminfo, or None when it can't be read (not Linux)."""
    try:
        with open('/proc/meminfo') as handle:
            for line in handle:
                if line.startswith('MemAvailable:'):
                    return int(line.split()[1]) // 1024
    except Exception:
        pass
    return None


def guide_services(settings):
    """{service ref: name} of every channel in the configured bouquets, each once, encrypted ones left out with fta_only."""
    encrypted = encrypted_services(settings.lamedb) if settings.fta_only else set()
    services = {}
    for ref, _ in selected_bouquets(settings):
        try:
            found = bouquet_services(settings, ref)
        except Exception as error:
            log.info('Guide: skipping a bouquet (%s)', error)
            continue
        for service in found:
            sref = service.get('servicereference', '')
            if sref.startswith('1:0:') and service_key(sref) not in (encrypted or set()):
                services.setdefault(sref, (service.get('servicename') or '').strip())
        time.sleep(EPG_PAUSE)
    return services


def build_epg(settings, now=None):
    """Gzipped XMLTV of the next EPG_DAYS days for the channels of the configured bouquets, read one channel at a time.
    Programmes are compressed as they are read, so the whole guide is never in memory uncompressed: the channel list and
    the programmes are two gzip members of one file (readers take them as one). Returns (gzipped XMLTV, summary)."""
    now = int(now or time.time())
    end = now + EPG_DAYS * 86400
    started = time.time()
    services = guide_services(settings)
    channels = {}
    packed = zlib.compressobj(6, zlib.DEFLATED, 31)
    chunks = []
    count = 0
    stopped = False

    for done, (sref, name) in enumerate(services.items(), 1):
        free = free_memory_mb()
        if free is not None and free < EPG_MIN_FREE_MB:
            log.warning('Guide: stopping after %d of %d channels, the receiver has only %d MB free', done - 1, len(services), free)
            stopped = True
            break
        try:
            events = json.loads(webif_get(settings, '/api/epgservice?' + urllib.parse.urlencode({'sRef': sref}), timeout=20)).get('events') or []
        except Exception:
            events = []
        seen = set()
        for event in events:
            begin = int(event.get('begin_timestamp') or event.get('begin') or 0)
            duration = int(event.get('duration_sec') or event.get('duration') or 0)
            title = (event.get('title') or '').strip()
            if not title or duration <= 0 or begin + duration < now or begin > end or begin in seen:
                continue
            seen.add(begin)
            channels.setdefault(sref, name or (event.get('sname') or '').strip() or sref)
            description = (event.get('longdesc') or event.get('shortdesc') or '').strip()[:EPG_MAX_DESCRIPTION]
            line = '<programme start="%s" stop="%s" channel=%s><title>%s</title>%s</programme>\n' % (
                xmltv_time(begin), xmltv_time(begin + duration), quoteattr(sref), escape(title), '<desc>%s</desc>' % escape(description) if description else '')
            chunks.append(packed.compress(line.encode('utf-8')))
            count += 1
        del events
        if done % 250 == 0:
            log.info('Guide: %d of %d channels read', done, len(services))
        time.sleep(EPG_PAUSE)

    chunks.append(packed.compress(b'</tv>\n'))
    chunks.append(packed.flush())
    head = ['<?xml version="1.0" encoding="UTF-8"?>', '<tv generator-info-name="NeboTV relay %s">' % VERSION]
    for sref, name in channels.items():
        head.append('<channel id=%s><display-name>%s</display-name></channel>' % (quoteattr(sref), escape(name)))
    body = gzip.compress(('\n'.join(head) + '\n').encode('utf-8')) + b''.join(chunks)
    summary = {'channels': len(channels), 'programmes': count, 'looked_at': len(services), 'bytes': len(body), 'at': int(time.time()), 'complete': not stopped}
    log.info('Guide: %d channels with programmes, %d programmes, %d channels looked at in %ds, %.1f MB compressed%s', len(channels), count, len(services), time.time() - started, len(body) / 1e6, ' (stopped early)' if stopped else '')
    return body, summary


# ---------------------------------------------------------------- HTTP server

class Relay:
    def __init__(self, settings):
        self.settings = settings
        self.active = {}  # service ref -> number of viewers
        self.events = collections.deque(maxlen=20)  # latest refusals and streams without video, for /status
        self.epg = b''  # gzipped XMLTV, read in the background (refresh_epg): reading the whole guide takes minutes
        self.epg_summary = None

    def note(self, kind, ref, reason):
        self.events.append({'t': int(time.time()), 'kind': kind, 'ref': ref, 'reason': reason})

    def tuners_in_use(self):
        """The transponders being played: one tuner each (channels on the same one share it)."""
        return {transponder_key(ref) or ref for ref in self.active}

    def refusal(self, ref):
        """Why this channel can't start now, or None when it can. Viewers of a channel that is already playing, and of
        another channel on the same transponder, share its tuner."""
        if ref in self.active:
            return None
        in_use = self.tuners_in_use()
        if (transponder_key(ref) or ref) in in_use:
            return None
        if len(in_use) >= self.settings.max_streams:
            return 'All tuners are busy'
        if self.settings.single_cable:
            satellites = {satellite_of(r) for r in self.active} - {None}
            satellite = satellite_of(ref)
            if satellite is not None and satellites and satellite not in satellites:
                return 'The receiver is on another satellite right now'
        return None

    async def handle(self, reader, writer):
        try:
            head = await asyncio.wait_for(reader.readuntil(b'\r\n\r\n'), timeout=15)
        except Exception:
            writer.close()
            return
        if len(head) > MAX_REQUEST_BYTES:
            return await self.reply(writer, 431, 'Request too large')

        request_line, *header_lines = head.decode('latin-1').split('\r\n')
        try:
            method, target, _ = request_line.split(' ', 2)
        except ValueError:
            return await self.reply(writer, 400, 'Bad request')
        headers = {}
        for line in header_lines:
            key, _, value = line.partition(':')
            headers[key.strip().lower()] = value.strip()
        if method not in ('GET', 'HEAD'):
            return await self.reply(writer, 405, 'Method not allowed')

        url = urllib.parse.urlsplit(target)
        query = dict(urllib.parse.parse_qsl(url.query))
        base_url = self.settings.public_url or 'http://' + headers.get('host', 'localhost:%d' % self.settings.port)

        try:
            if url.path.startswith('/s/') and url.path.endswith('.ts'):
                return await self.stream(writer, url.path[3:-3], query, method)
            if url.path == '/playlist.m3u':
                if not self.authorised(query):
                    return await self.reply(writer, 403, 'Forbidden')
                body = await asyncio.get_running_loop().run_in_executor(None, build_playlist, self.settings, base_url)
                return await self.reply(writer, 200, body, 'audio/x-mpegurl')
            if url.path == '/epg.xml':
                if not self.authorised(query):
                    return await self.reply(writer, 403, 'Forbidden')
                if not self.settings.guide:
                    return await self.reply(writer, 404, 'The guide is switched off on the receiver (guide = no)')
                if not self.epg:
                    return await self.reply(writer, 503, 'The guide is still being read, try again in a few minutes', retry_after=300)
                return await self.reply(writer, 200, self.epg, 'application/gzip')
            if url.path == '/status':
                if not self.authorised(query):
                    return await self.reply(writer, 403, 'Forbidden')
                return await self.reply(writer, 200, json.dumps(await self.status()), 'application/json')
            if url.path.startswith('/picon/') and re.fullmatch(r'/picon/[\w.-]+\.png', url.path):
                data = await asyncio.get_running_loop().run_in_executor(None, webif_get, self.settings, url.path)
                return await self.reply(writer, 200, data, 'image/png', cache=True)
        except Exception as error:  # receiver unreachable, bad bouquet, ...
            log.warning('%s %s failed: %s', method, url.path, error)
            return await self.reply(writer, 502, 'Receiver error')
        return await self.reply(writer, 404, 'Not found')

    async def refresh_epg(self, first_delay=EPG_FIRST_DELAY):
        """Read the guide EPG_FIRST_DELAY after the start (streams come first), then every EPG_REFRESH_SECONDS."""
        if not self.settings.guide:
            return
        await asyncio.sleep(first_delay)
        while True:
            try:
                self.epg, self.epg_summary = await asyncio.get_running_loop().run_in_executor(None, build_epg, self.settings)
            except Exception as error:
                log.warning('Reading the guide failed: %s', error)
            await asyncio.sleep(EPG_REFRESH_SECONDS)

    def authorised(self, query):
        return hmac.compare_digest(query.get('key', ''), self.settings.secret)

    async def status(self):
        loop = asyncio.get_running_loop()
        try:
            await loop.run_in_executor(None, webif_get, self.settings, '/api/statusinfo', 5)
            box = True
        except Exception:
            box = False
        return {
            'ok': box, 'box': box, 'version': VERSION,
            'active_channels': len(self.active), 'active_tuners': len(self.tuners_in_use()), 'max_streams': self.settings.max_streams,
            'single_cable': self.settings.single_cable, 'satellites': sorted({satellite_of(r) for r in self.active} - {None}),
            'recent_events': list(self.events),
            'guide': self.epg_summary if self.settings.guide else False,
        }

    async def stream(self, writer, encoded, query, method):
        settings = self.settings
        ref = decode_ref(encoded)
        expires, transcode, signature = query.get('e', ''), query.get('x', '0'), query.get('s', '')
        if not ref or not expires.isdigit() or transcode not in ('0', '1'):
            return await self.reply(writer, 400, 'Bad link')
        if not hmac.compare_digest(sign(settings.secret, encoded, expires, transcode), signature):
            return await self.reply(writer, 403, 'Invalid link')
        if int(expires) < time.time():
            return await self.reply(writer, 403, 'Link expired')
        reason = self.refusal(ref)
        if reason:
            log.warning('Refused %s: %s (%d tuners in use)', ref, reason, len(self.tuners_in_use()))
            self.note('refused', ref, reason)
            return await self.reply(writer, 503, reason, retry_after=10)
        if method == 'HEAD':
            return await self.reply(writer, 200, '', 'video/mp2t')

        port = settings.transcode_port if transcode == '1' else settings.stream_port
        try:
            up_reader, up_writer = await asyncio.wait_for(asyncio.open_connection(settings.box_host, port), timeout=10)
        except Exception as error:
            log.warning('Cannot reach receiver port %d: %s', port, error)
            return await self.reply(writer, 502, 'Receiver unreachable')

        auth = base64.b64encode(('%s:%s' % (settings.box_user, settings.box_password)).encode()).decode()
        up_writer.write(('GET /%s HTTP/1.0\r\nHost: %s\r\nAuthorization: Basic %s\r\n\r\n' % (ref, settings.box_host, auth)).encode())
        await up_writer.drain()

        try:
            up_head = await asyncio.wait_for(up_reader.readuntil(b'\r\n\r\n'), timeout=15)
        except Exception:
            up_writer.close()
            return await self.reply(writer, 502, 'Receiver did not answer')
        status = up_head.split(b' ', 2)[1:2]
        if status != [b'200']:
            up_writer.close()
            code = status[0].decode() if status else '?'
            log.warning('Receiver answered %s for %s', code, ref)
            return await self.reply(writer, 502, 'Receiver refused the stream (HTTP %s)' % code)

        self.active[ref] = self.active.get(ref, 0) + 1
        log.info('Start %s (%d channels, %d tuners active)', ref, len(self.active), len(self.tuners_in_use()))
        sent = 0
        try:
            writer.write(b'HTTP/1.1 200 OK\r\nContent-Type: video/mp2t\r\nCache-Control: no-cache\r\nConnection: close\r\n\r\n')
            while True:
                chunk = await up_reader.read(CHUNK)
                if not chunk:
                    break
                sent += len(chunk)
                writer.write(chunk)
                await writer.drain()
        except (ConnectionError, asyncio.IncompleteReadError, OSError):
            pass  # viewer closed the player
        finally:
            up_writer.close()
            writer.close()
            self.active[ref] -= 1
            if self.active[ref] <= 0:
                del self.active[ref]
            log.info('Stop %s (%d channels, %d tuners active)', ref, len(self.active), len(self.tuners_in_use()))
            if sent == 0:
                log.warning('No video from the receiver for %s: its tuner could not lock (busy on another satellite, no signal or encrypted?)', ref)
                self.note('no_video', ref, 'The receiver sent no video')

    async def reply(self, writer, status, body, content_type='text/plain; charset=utf-8', cache=False, retry_after=None):
        reasons = {200: 'OK', 400: 'Bad Request', 403: 'Forbidden', 404: 'Not Found', 405: 'Method Not Allowed',
                   431: 'Request Header Fields Too Large', 502: 'Bad Gateway', 503: 'Service Unavailable'}
        data = body if isinstance(body, bytes) else body.encode()
        head = 'HTTP/1.1 %d %s\r\nContent-Type: %s\r\nContent-Length: %d\r\nConnection: close\r\n%s\r\n' % (
            status, reasons.get(status, ''), content_type, len(data), ('Cache-Control: max-age=86400\r\n' if cache else '') + ('Retry-After: %d\r\n' % retry_after if retry_after else ''))
        try:
            writer.write(head.encode() + data)
            await writer.drain()
        except Exception:
            pass
        writer.close()


async def update_duckdns(settings):
    """Point <domain>.duckdns.org at this connection's public IP every 5 minutes."""
    url = 'https://www.duckdns.org/update?' + urllib.parse.urlencode({'domains': settings.duckdns_domain, 'token': settings.duckdns_token, 'ip': ''})
    while True:
        try:
            result = await asyncio.get_running_loop().run_in_executor(None, lambda: urllib.request.urlopen(url, timeout=20).read().decode().strip())
            if result != 'OK':
                log.warning('DuckDNS update answered %s', result)
        except Exception as error:
            log.warning('DuckDNS update failed: %s', error)
        await asyncio.sleep(300)


async def main():
    settings = load_settings()
    relay = Relay(settings)
    server = await asyncio.start_server(relay.handle, '0.0.0.0', settings.port)
    log.info('NeboTV relay %s on port %d, max %d tuners%s, bouquets: %s', VERSION, settings.port, settings.max_streams, ' (one cable: one satellite at a time)' if settings.single_cable else '', ', '.join(settings.bouquets) or 'all')
    if settings.duckdns_domain and settings.duckdns_token:
        asyncio.ensure_future(update_duckdns(settings))
    asyncio.ensure_future(relay.refresh_epg())
    async with server:
        await server.serve_forever()


if __name__ == '__main__':
    logging.basicConfig(level=logging.INFO, format='%(asctime)s %(levelname)s %(message)s', stream=sys.stdout)
    if len(sys.argv) > 1 and sys.argv[1] == '--playlist':
        print(build_playlist(load_settings(), 'http://example'))  # check the bouquet selection
    else:
        asyncio.run(main())
