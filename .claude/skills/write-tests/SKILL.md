---
name: write-tests
description: Write Pest feature/unit tests for the IPTV service (admin CRUD, auth, /m3u playlist, /play access rules and connection limits). Use when adding or changing behaviour, fixing a bug, or when asked to add test coverage.
---

# Write tests

Pest 4 + `pestphp/pest-plugin-laravel`. Tests run on sqlite `:memory:` (see `phpunit.xml`). Run with `composer test` or `php artisan test --filter=...`.

## Setup facts
- `tests/Pest.php` applies `RefreshDatabase` to everything in `tests/Feature` (subfolders included).
- `User::factory()` defaults to an active subscriber (password `password`, 1 connection, expires in a month). States: `admin()`, `disabled()`, `expired()`.
- Helper `subscribedChannel($user, [...channel attrs])` creates an active channel in a new bouquet assigned to the user.
- Other models have no factories; create them with `Model::create([...])`.
- Pest helper functions are global: give file-local helpers unique names (e.g. `tokenFor()` in PlayTest, `channelPayload()` in Admin/ChannelTest).

## Conventions
- File per feature: `tests/Feature/Admin/ChannelTest.php`, `tests/Feature/M3uTest.php`, `tests/Feature/PlayTest.php`, `tests/Unit/...` for pure logic.
- Admin tests: `$this->actingAs(User::factory()->admin()->create())`.
- Name tests after behaviour: `it('refuses play when connection limit is reached')`.
- For `/play`, assert a redirect to the channel URL: `->assertRedirect($channel->stream_url)`. Assert status codes 401/403/404 for the failure branches.
- For `/m3u`, assert `Content-Type` `audio/x-mpegurl`, `#EXTM3U` header, one `/play/` line per active channel in the user's bouquets, and no inactive channels.
- Use `$this->travel(6)->minutes()` to test the 5-minute session window; `$this->freezeSecond()` when comparing timestamps.
- Existing suites to extend: `AuthTest`, `M3uTest`, `PlayTest`, `SeederTest`, `Admin/ChannelTest`, `Admin/ValidationErrorsTest`.

## Must-cover scenarios for streaming changes
valid play · bad token · expired token · unknown channel · disabled user · expired user · inactive channel · channel outside bouquets · limit reached on a different channel (403) · limit reached on the same channel (allowed) · stream_logs row written · active_sessions upserted.

After writing tests, run them and report real results. Then follow `update-docs`.
