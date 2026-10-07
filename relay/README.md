# NeboTV relay

Runs on an Enigma2 satellite receiver (e.g. Octagon SF8008, OpenATV) so viewers outside the home can watch its free-to-air channels through the NeboTV panel.

- `nebotv-relay.py`: the relay (Python 3, standard library only)
- `nebotv-relay.conf.example`: settings, copy to `/etc/nebotv-relay.conf`
- `nebotv-relay.init`: start script, copy to `/etc/init.d/nebotv-relay`
- `test_relay.py`: tests (`python3 relay/test_relay.py`)

Setup guide: [docs/features/receiver.md](../docs/features/receiver.md).
