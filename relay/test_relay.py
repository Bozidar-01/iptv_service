"""Tests for nebotv-relay.py. Run: python3 relay/test_relay.py"""
import asyncio
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


class FakeReceiver:
    """Answers like Enigma2's streaming port: checks the login, then sends a little TS data."""
    def __init__(self):
        self.requests = []
        # A stream stays open until this is set (set by default); tests that need it to stay busy clear it first
        self.release = asyncio.Event()
        self.release.set()

    async def handle(self, reader, writer):
        head = (await reader.readuntil(b'\r\n\r\n')).decode()
        self.requests.append(head)
        if 'Authorization: Basic cm9vdDpib3hwYXNz' not in head:  # root:boxpass
            writer.write(b'HTTP/1.0 401 Unauthorized\r\n\r\n')
        else:
            writer.write(b'HTTP/1.0 200 OK\r\nContent-Type: video/mpeg\r\n\r\n' + b'\x47' * 1880)
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

    async def test_limits_channels_to_the_tuners(self):
        other = '1:0:19:D4A:1450:13E:820000:0:0:0:'
        self.box.release.clear()   # the first stream stays open until we say so, however slow the machine is
        first = asyncio.ensure_future(self.get(self.link()))
        for _ in range(250):       # wait (up to 5 s) until the relay has taken the tuner
            if self.relay.active:
                break
            await asyncio.sleep(0.02)
        self.assertTrue(self.relay.active)

        busy = await self.get(self.link(other))
        same = asyncio.ensure_future(self.get(self.link()))
        await asyncio.sleep(0.2)
        self.box.release.set()
        self.assertEqual(busy[0], 503)   # another channel needs a second tuner
        self.assertEqual((await same)[0], 200)   # same channel shares the tuner
        await first

    async def test_protects_playlist_and_status(self):
        self.assertEqual((await self.get('http://127.0.0.1:%d/status' % self.port))[0], 403)
        self.assertEqual((await self.get('http://127.0.0.1:%d/playlist.m3u?key=wrong' % self.port))[0], 403)


if __name__ == '__main__':
    unittest.main(verbosity=1)
