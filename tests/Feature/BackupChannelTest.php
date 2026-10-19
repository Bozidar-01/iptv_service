<?php

use App\Models\Channel;
use App\Models\User;
use App\Services\StreamCheckService;
use App\Services\TokenService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake([
        'live.test/*' => fn () => Http::response("#EXTM3U\n#EXT-X-TARGETDURATION:6\n#EXT-X-MEDIA-SEQUENCE:1\n#EXTINF:6.0,\nseg1.ts\n", 200),
        'dead.test/*' => fn () => Http::response('', 404),
    ]);
});

function tvChannel(string $url, array $attributes = []): Channel
{
    return Channel::create(array_merge(['name' => 'Rai 1', 'stream_url' => $url, 'is_active' => true], $attributes));
}

function checkAll(): void
{
    app(StreamCheckService::class)->checkChannels(Channel::pluck('id')->all());
}

it('keeps a dead channel on and plays a working channel with the same guide ID', function () {
    $dead = tvChannel('http://dead.test/rai1', ['tvg_id' => 'Rai1.it']);
    $twin = tvChannel('http://live.test/rai1', ['tvg_id' => 'Rai1.it']);

    checkAll();

    expect($dead->fresh())->is_active->toBeTrue()->auto_disabled->toBeFalse()
        ->stream_status->toBe('offline')->using_backup_id->toBe($twin->id)
        ->and($dead->fresh()->playbackChannel()->id)->toBe($twin->id);
});

it('prefers the backup the admin chose', function () {
    $dead = tvChannel('http://dead.test/a', ['tvg_id' => 'Rai1.it']);
    tvChannel('http://live.test/twin', ['tvg_id' => 'Rai1.it']);
    $chosen = tvChannel('http://live.test/chosen', ['name' => 'Rai Uno (other provider)']);
    $dead->update(['backup_channel_id' => $chosen->id]);

    checkAll();

    expect($dead->fresh()->using_backup_id)->toBe($chosen->id);
});

it('switches off a dead channel when no backup works, and goes back to its own stream when it recovers', function () {
    $dead = tvChannel('http://dead.test/a', ['tvg_id' => 'Rai1.it']);
    $deadTwin = tvChannel('http://dead.test/b', ['tvg_id' => 'Rai1.it']);

    checkAll();
    expect($dead->fresh())->is_active->toBeFalse()->using_backup_id->toBeNull();

    // A backup appears later: the switched-off channel comes back, playing it
    $twin = tvChannel('http://live.test/c', ['tvg_id' => 'Rai1.it', 'stream_status' => 'online']);
    checkAll();
    expect($dead->fresh())->is_active->toBeTrue()->auto_disabled->toBeFalse()->using_backup_id->toBe($twin->id);

    // Its own stream works again
    $dead->update(['stream_url' => 'http://live.test/a']);
    checkAll();
    expect($dead->fresh())->stream_status->toBe('online')->using_backup_id->toBeNull();
});

it('never uses a disabled channel or one that is itself on a backup', function () {
    $dead = tvChannel('http://dead.test/a', ['tvg_id' => 'Rai1.it']);
    tvChannel('http://live.test/off', ['tvg_id' => 'Rai1.it', 'is_active' => false, 'stream_status' => 'online']);

    checkAll();

    expect($dead->fresh())->using_backup_id->toBeNull()->is_active->toBeFalse();
});

it('sends viewers to the backup stream', function () {
    $user = User::factory()->create();
    $dead = subscribedChannel($user, ['stream_url' => 'http://dead.test/a', 'tvg_id' => 'Rai1.it']);
    $twin = tvChannel('http://live.test/backup', ['tvg_id' => 'Rai1.it']);
    checkAll();
    $token = app(TokenService::class)->generateToken($user)->token;

    $this->get(route('play', ['channel_id' => $dead->id, 'token' => $token]))->assertRedirect('http://live.test/backup');
});

describe('admin', function () {
    beforeEach(fn () => $this->actingAs(User::factory()->admin()->create()));

    it('shows channels playing a backup and filters them', function () {
        $dead = tvChannel('http://dead.test/a', ['name' => 'Rai 1 broken', 'tvg_id' => 'Rai1.it']);
        tvChannel('http://live.test/b', ['name' => 'Rai 1 fine', 'tvg_id' => 'Rai1.it']);
        checkAll();

        $this->get(route('admin.channels.index', ['status' => 'backup']))
            ->assertOk()->assertSee('Rai 1 broken')->assertSee('Playing a backup')->assertDontSee('Rai 1 fine</div>', false);
    });

    it('offers channels with the same name or guide ID as backups and saves the choice', function () {
        $channel = tvChannel('http://live.test/a', ['tvg_id' => 'Rai1.it']);
        $twin = tvChannel('http://live.test/b', ['name' => 'Rai 1 HD', 'tvg_id' => 'Rai1.it']);
        tvChannel('http://live.test/c', ['name' => 'Unrelated']);

        $this->get(route('admin.channels.edit', $channel))->assertOk()->assertSee('Rai 1 HD')->assertDontSee('Unrelated');

        $this->put(route('admin.channels.update', $channel), ['name' => 'Rai 1', 'stream_url' => 'http://live.test/a', 'is_active' => '1', 'backup_channel_id' => $twin->id]);
        expect($channel->fresh()->backup_channel_id)->toBe($twin->id);

        $this->put(route('admin.channels.update', $channel), ['name' => 'Rai 1', 'stream_url' => 'http://live.test/a', 'is_active' => '1', 'backup_channel_id' => $channel->id])
            ->assertSessionHasErrors('backup_channel_id');
    });
});
