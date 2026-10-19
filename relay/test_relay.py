"""Tests for nebotv-relay.py. Run: python3 relay/test_relay.py"""
import asyncio
import gzip
import http.server
import json
import threading
import urllib.parse
from xml.etree import ElementTree
import importlib.util
import os
import tempfile
import time
import unittest
import urllib.request

spec = importlib.util.spec_from_file_location('relay', os.path.join(os.path.dirname(__file__), 'nebotv-relay.py'))
relay = importlib.util.module_from_spec(spec)
spec.loader.exec_module(relay)

RAI1 = '1:0:19:D49:1450:13E:820000:0:0:0:'
LAMEDB4 = """eDVB services /4/
transponders
end
services
0d49:00820000:1450:013e:25:0:0
Rai 1 HD
p:Rai,c:15...
007a:00820000:04b0:0110:25:0:0
Canale5 HD
p:Mediaset,c:15...,C:1802,C:0500
end
"""
LAMEDB5 = """eDVB services /5/
s:0d49:00820000:1450:013e:25:0:0,"Rai 1 HD",p:Rai
s:007a:00820000:04b0:0110:25:0:0,"Canale5 HD",p:Mediaset,C:1802
"""


def write(content):
    handle = tempfile.NamedTemporaryFile('w', delete=False, suffix='.db')
    handle.write(content)
    handle.close()
    return handle.name


class SigningTest(unittest.TestCase):
    def test_matches_the_panel(self):
        # Same vector as tests/Feature/ReceiverTest.php
        encoded = relay.encode_ref(RAI1)
        self.assertEqual(encoded, 'MTowOjE5OkQ0OToxNDUwOjEzRTo4MjAwMDA6MDowOjA6')
        self.assertEqual(relay.sign('test-secret-1234567890', encoded, 1800000000, '1'), 'c4587a499d69f117e64357936adc4e45')

    def test_decodes_only_service_references(self):
        self.assertEqual(relay.decode_ref(relay.encode_ref(RAI1)), RAI1)
        self.assertIsNone(relay.decode_ref(relay.encode_ref('../../etc/passwd')))
        self.assertIsNone(relay.decode_ref('!!!'))


class LamedbTest(unittest.TestCase):
    def test_version_4_and_5(self):
        for content in (LAMEDB4, LAMEDB5):
            encrypted = relay.encrypted_services(write(content))
            self.assertIn(relay.service_key('1:0:19:7A:4B0:110:820000:0:0:0:'), encrypted)
            self.assertNotIn(relay.service_key(RAI1), encrypted)

    def test_missing_file(self):
        self.assertIsNone(relay.encrypted_services('/nonexistent/lamedb'))

    def test_clean_names(self):
        self.assertEqual(relay.clean_name('::| RAI |::'), 'RAI')
        self.assertEqual(relay.clean_name('• 13,0 E - RAI • Mediaset • TivùSat'), '13,0 E - RAI Mediaset TivùSat')


# Same transponder as RAI1 (tsid 1450, onid 13E, namespace 820000), another channel on it
RAI_SAME_TRANSPONDER = '1:0:1:64D:1450:13E:820000:0:0:0:'
# 13.0 E, another transponder
HOTBIRD_OTHER = '1:0:19:D4A:1451:13E:820000:0:0:0:'
# 19.2 E (namespace C00000)
ASTRA = '1:0:1:100:2000:13E:C00000:0:0:0:'


class TransponderTest(unittest.TestCase):
    def test_transponder_key_and_satellite(self):
        self.assertEqual(relay.transponder_key(RAI1), (0x1450, 0x13E, 0x820000))
        self.assertEqual(relay.transponder_key(RAI_SAME_TRANSPONDER), relay.transponder_key(RAI1))
        self.assertNotEqual(relay.transponder_key(HOTBIRD_OTHER), relay.transponder_key(RAI1))
        self.assertIsNone(relay.transponder_key('1:0:1'))

        self.assertEqual(relay.satellite_of(RAI1), 130)        # 13.0 E
        self.assertEqual(relay.satellite_of(ASTRA), 192)       # 19.2 E
        self.assertEqual(relay.satellite_of('1:0:1:1:1:1:A00000:0:0:0:'), 160)
        self.assertEqual(relay.satellite_of('1:0:1:1:1:1:E080000:0:0:0:'), 0xE08)    # west positions are 3600 - degrees
        self.assertIsNone(relay.satellite_of('1:0:1:1:1:1:EEEE0000:0:0:0:'))         # terrestrial
        self.assertIsNone(relay.satellite_of('1:0:1:1:1:1:FFFF0000:0:0:0:'))         # cable
        self.assertIsNone(relay.satellite_of('1:0:1:1:1:1:0:0:0:0:'))                # stream reference
        self.assertIsNone(relay.satellite_of('garbage'))

    def settings(self, **over):
        conf = write('[relay]\nsecret = test-secret-1234567890\n')
        settings = relay.Settings(conf)
        for key, value in over.items():
            setattr(settings, key, value)
        return settings

    def test_channels_on_one_transponder_share_a_tuner(self):
        box = relay.Relay(self.settings(max_streams=1))
        box.active[RAI1] = 1

        self.assertIsNone(box.refusal(RAI1))                       # the same channel
        self.assertIsNone(box.refusal(RAI_SAME_TRANSPONDER))       # another channel on the same transponder
        self.assertEqual(box.refusal(HOTBIRD_OTHER), 'All tuners are busy')
        self.assertEqual(box.refusal(ASTRA), 'All tuners are busy')

    def test_two_tuners_two_transponders(self):
        box = relay.Relay(self.settings(max_streams=2))
        box.active[RAI1] = 1
        box.active[RAI_SAME_TRANSPONDER] = 1        # still one tuner

        self.assertIsNone(box.refusal(HOTBIRD_OTHER))
        box.active[HOTBIRD_OTHER] = 1
        self.assertEqual(box.refusal(ASTRA), 'All tuners are busy')

    def test_one_cable_means_one_satellite(self):
        box = relay.Relay(self.settings(max_streams=2, single_cable=True))
        box.active[RAI1] = 1

        self.assertIsNone(box.refusal(HOTBIRD_OTHER))                                   # same satellite
        self.assertEqual(box.refusal(ASTRA), 'The receiver is on another satellite right now')
        self.assertIsNone(box.refusal('1:0:1:1:1:1:EEEE0000:0:0:0:'))                   # not a satellite channel

        box.active.clear()
        self.assertIsNone(box.refusal(ASTRA))                                           # nothing playing: any satellite

        # With two cables (the default) the other satellite is fine
        two = relay.Relay(self.settings(max_streams=2, single_cable=False))
        two.active[RAI1] = 1
        self.assertIsNone(two.refusal(ASTRA))


class FakeReceiver:
    """Answers like Enigma2's streaming port: checks the login, then sends a little TS data."""
    def __init__(self):
        self.requests = []
        # A stream stays open until this is set (set by default); tests that need it to stay busy clear it first
        self.release = asyncio.Event()
        self.release.set()
        self.silent = False   # accept the stream but send no video, like a tuner that can't lock

    async def handle(self, reader, writer):
        head = (await reader.readuntil(b'\r\n\r\n')).decode()
        self.requests.append(head)
        if 'Authorization: Basic cm9vdDpib3hwYXNz' not in head:  # root:boxpass
            writer.write(b'HTTP/1.0 401 Unauthorized\r\n\r\n')
        else:
            writer.write(b'HTTP/1.0 200 OK\r\nContent-Type: video/mpeg\r\n\r\n' + (b'' if self.silent else b'\x47' * 1880))
            await writer.drain()
            await asyncio.wait_for(self.release.wait(), 10)
        writer.close()


class StreamTest(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.box = FakeReceiver()
        self.box_server = await asyncio.start_server(self.box.handle, '127.0.0.1', 0)
        conf = write('[relay]\nsecret = test-secret-1234567890\nmax_streams = 1\nbox_host = 127.0.0.1\n'
                     'stream_port = %d\nbox_password = boxpass\n' % self.box_server.sockets[0].getsockname()[1])
        self.relay = relay.Relay(relay.Settings(conf))
        self.server = await asyncio.start_server(self.relay.handle, '127.0.0.1', 0)
        self.port = self.server.sockets[0].getsockname()[1]

    async def asyncTearDown(self):
        self.server.close()
        self.box_server.close()

    def link(self, ref=RAI1, expires=None, signature=None):
        encoded = relay.encode_ref(ref)
        expires = expires or int(time.time()) + 60
        signature = signature or relay.sign('test-secret-1234567890', encoded, expires, '0')
        return 'http://127.0.0.1:%d/s/%s.ts?e=%d&x=0&s=%s' % (self.port, encoded, expires, signature)

    async def get(self, url):
        def fetch():
            try:
                with urllib.request.urlopen(url, timeout=5) as response:
                    return response.status, response.read()
            except urllib.error.HTTPError as error:
                return error.code, error.read()
        return await asyncio.get_running_loop().run_in_executor(None, fetch)

    async def test_streams_a_signed_link_with_the_box_login(self):
        status, body = await self.get(self.link())
        self.assertEqual(status, 200)
        self.assertEqual(len(body), 1880)
        self.assertIn('GET /%s HTTP/1.0' % RAI1, self.box.requests[0])
        self.assertEqual(self.relay.active, {})

    async def test_refuses_forged_and_expired_links(self):
        self.assertEqual((await self.get(self.link(signature='0' * 32)))[0], 403)
        self.assertEqual((await self.get(self.link(expires=int(time.time()) - 5)))[0], 403)
        self.assertEqual(self.box.requests, [])

    async def hold_first_stream(self, ref=RAI1):
        """Start a stream that stays open until box.release is set, however slow the machine is; waits until the relay has it."""
        self.box.release.clear()
        first = asyncio.ensure_future(self.get(self.link(ref)))
        for _ in range(250):       # up to 5 s
            if self.relay.active:
                break
            await asyncio.sleep(0.02)
        self.assertTrue(self.relay.active)
        return first

    async def test_limits_channels_to_the_tuners(self):
        first = await self.hold_first_stream()

        busy = await self.get(self.link(HOTBIRD_OTHER))       # another transponder needs a second tuner
        same = asyncio.ensure_future(self.get(self.link()))
        await asyncio.sleep(0.2)
        self.box.release.set()
        self.assertEqual(busy[0], 503)
        self.assertEqual(busy[1], b'All tuners are busy')
        self.assertEqual((await same)[0], 200)                # the same channel shares the tuner
        await first

    async def test_channels_on_the_same_transponder_share_the_tuner(self):
        first = await self.hold_first_stream()

        sibling = asyncio.ensure_future(self.get(self.link(RAI_SAME_TRANSPONDER)))
        await asyncio.sleep(0.2)
        self.assertEqual(len(self.relay.active), 2)           # two channels playing ...
        self.assertEqual(len(self.relay.tuners_in_use()), 1)  # ... on one tuner
        self.box.release.set()
        self.assertEqual((await sibling)[0], 200)
        await first

    async def test_one_cable_refuses_another_satellite_at_once(self):
        self.relay.settings.max_streams = 2
        self.relay.settings.single_cable = True
        first = await self.hold_first_stream()

        started = time.time()
        astra = await self.get(self.link(ASTRA))
        self.assertEqual(astra[0], 503)
        self.assertEqual(astra[1], b'The receiver is on another satellite right now')
        self.assertLess(time.time() - started, 1)             # at once, not after the receiver gave up
        self.assertEqual(len(self.box.requests), 1)           # the receiver was never asked

        other_transponder = asyncio.ensure_future(self.get(self.link(HOTBIRD_OTHER)))   # same satellite: fine
        await asyncio.sleep(0.2)
        self.box.release.set()
        self.assertEqual((await other_transponder)[0], 200)
        await first

    async def test_status_reports_the_tuners(self):
        self.relay.settings.single_cable = True
        first = await self.hold_first_stream()

        status = await self.relay.status()
        self.assertEqual((status['active_channels'], status['active_tuners'], status['max_streams']), (1, 1, 1))
        self.assertEqual((status['single_cable'], status['satellites'], status['version']), (True, [130], relay.VERSION))
        self.box.release.set()
        await first

    async def test_says_so_when_the_receiver_sends_no_video(self):
        self.box.silent = True
        with self.assertLogs('nebotv-relay', level='WARNING') as logs:
            status, body = await self.get(self.link())
        self.assertEqual((status, body), (200, b''))
        self.assertTrue(any('No video from the receiver' in line for line in logs.output))
        events = (await self.relay.status())['recent_events']
        self.assertEqual([(e['kind'], e['ref']) for e in events], [('no_video', RAI1)])

    async def test_remembers_refusals_for_the_panel(self):
        first = await self.hold_first_stream()
        await self.get(self.link(HOTBIRD_OTHER))
        self.box.release.set()
        await first

        events = (await self.relay.status())['recent_events']
        self.assertEqual([(e['kind'], e['ref'], e['reason']) for e in events], [('refused', HOTBIRD_OTHER, 'All tuners are busy')])
        self.assertIsInstance(events[0]['t'], int)

    async def test_protects_playlist_and_status(self):
        self.assertEqual((await self.get('http://127.0.0.1:%d/status' % self.port))[0], 403)
        self.assertEqual((await self.get('http://127.0.0.1:%d/epg.xml?key=wrong' % self.port))[0], 403)

    async def test_says_the_guide_is_not_ready_until_it_was_read(self):
        url = 'http://127.0.0.1:%d/epg.xml?key=test-secret-1234567890' % self.port
        self.assertEqual((await self.get(url))[0], 503)
        self.relay.epg = gzip.compress(b'<tv></tv>')
        self.assertEqual(await self.get(url), (200, self.relay.epg))
        self.assertEqual((await self.get('http://127.0.0.1:%d/playlist.m3u?key=wrong' % self.port))[0], 403)


class FakeWebif(http.server.BaseHTTPRequestHandler):
    """OpenWebif's JSON API: one bouquet with two channels, guide events for one of them."""
    multi = True

    paths = []

    broken = False  # the box stops answering getservices

    def do_GET(self):
        FakeWebif.paths.append(self.path)
        url = urllib.parse.urlsplit(self.path)
        query = dict(urllib.parse.parse_qsl(url.query))
        now = int(query.get('time') or time.time())
        events = [
            {'sref': RAI1, 'sname': 'Rai 1 HD', 'title': 'Tg1 & Meteo', 'longdesc': 'News <live>', 'begin_timestamp': 1792400000, 'duration_sec': 1800},
            {'sref': RAI1, 'sname': 'Rai 1 HD', 'title': 'Old one', 'begin_timestamp': 1, 'duration_sec': 60},
        ]
        if FakeWebif.broken and url.path == '/api/getservices':
            self.send_response(500)
            self.end_headers()
            return
        body = {
            '/api/bouquets': {'bouquets': [['1:7:1:0:0:0:0:0:0:0:FROM BOUQUET "userbouquet.it.tv"', 'Italia'], ['1:7:1:x', 'Other']]},
            '/api/epgmulti': ({'events': events if 'it.tv' in query.get('bRef', '') else []} if FakeWebif.multi else None),
            '/api/getservices': {'services': [{'servicereference': RAI1, 'servicename': 'Rai 1 HD'}, {'servicereference': '1:0:1:7A:4B0:110:820000:0:0:0:', 'servicename': 'Canale5'}]},
            '/api/epgservice': {'events': [{k: v for k, v in e.items() if k not in ('sref', 'sname')} for e in events] if query.get('sRef') == RAI1 else []},
        }.get(url.path)
        self.send_response(200 if body is not None else 404)
        self.end_headers()
        self.wfile.write(json.dumps(body or {}).encode())

    def log_message(self, *args):
        pass


class EpgTest(unittest.TestCase):
    def setUp(self):
        self.server = http.server.HTTPServer(('127.0.0.1', 0), FakeWebif)
        threading.Thread(target=self.server.serve_forever, daemon=True).start()
        conf = write('[relay]\nsecret = test-secret-1234567890\nbox_host = 127.0.0.1\nwebif_port = %d\nbouquets = Italia\nfta_only = no\n' % self.server.server_address[1])
        self.settings = relay.Settings(conf)
        self.pause, relay.EPG_PAUSE = relay.EPG_PAUSE, 0
        self.memory = relay.free_memory_mb

    def tearDown(self):
        self.server.shutdown()
        FakeWebif.multi = True
        FakeWebif.paths = []
        FakeWebif.broken = False
        relay._bouquet_cache.clear()
        relay.EPG_PAUSE = self.pause
        relay.free_memory_mb = self.memory

    def test_reads_each_channel_once_and_writes_xmltv(self):
        body, summary = relay.build_epg(self.settings, now=1792399000)
        self.assertEqual(body[:2], b'\x1f\x8b')  # gzip
        root = ElementTree.fromstring(gzip.decompress(body))
        self.assertEqual((summary['channels'], summary['programmes'], summary['looked_at'], summary['complete']), (1, 1, 2, True))
        self.assertEqual([c.get('id') for c in root.findall('channel')], [RAI1])
        self.assertEqual([c.findtext('display-name') for c in root.findall('channel')], ['Rai 1 HD'])
        programmes = root.findall('programme')
        self.assertEqual(len(programmes), 1)  # the one that already ended is left out
        self.assertEqual(programmes[0].get('start'), relay.xmltv_time(1792400000))
        self.assertEqual(programmes[0].get('stop'), relay.xmltv_time(1792401800))
        self.assertEqual(programmes[0].findtext('title'), 'Tg1 & Meteo')
        self.assertEqual(programmes[0].findtext('desc'), 'News <live>')
        # Never the whole bouquet at once: on a big bouquet that can use more memory than the receiver has
        self.assertFalse(any('/api/epgmulti' in p for p in FakeWebif.paths))
        self.assertEqual(sum('/api/epgservice' in p for p in FakeWebif.paths), 2)

    def test_a_bouquet_that_stops_answering_keeps_its_last_channel_list(self):
        before = relay.build_playlist(self.settings, 'http://relay.test')
        self.assertIn('Rai 1 HD', before)

        FakeWebif.broken = True
        self.assertEqual(relay.build_playlist(self.settings, 'http://relay.test'), before)

        # Never read before: fail (the panel keeps its channels) rather than send a list without them
        relay._bouquet_cache.clear()
        with self.assertRaises(Exception):
            relay.build_playlist(self.settings, 'http://relay.test')

    def test_stops_when_the_receiver_runs_low_on_memory(self):
        relay.free_memory_mb = lambda: 50
        body, summary = relay.build_epg(self.settings, now=1792399000)
        self.assertEqual(ElementTree.fromstring(gzip.decompress(body)).findall('programme'), [])
        self.assertFalse(summary['complete'])
        self.assertFalse(any('/api/epgservice' in p for p in FakeWebif.paths))

    def test_can_be_switched_off(self):
        conf = write('[relay]\nsecret = test-secret-1234567890\nguide = no\n')
        settings = relay.Settings(conf)
        self.assertFalse(settings.guide)
        asyncio.run(relay.Relay(settings).refresh_epg(first_delay=0))  # returns at once instead of reading
        self.assertEqual(FakeWebif.paths, [])


if __name__ == '__main__':
    unittest.main(verbosity=1)
