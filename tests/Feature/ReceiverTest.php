<?php

use App\Models\Bouquet;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Source;
use App\Models\StreamAlert;
use App\Models\User;
use App\Services\ReceiverService;
use App\Services\SourceSyncService;
use App\Services\StreamCheckService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const RELAY = 'http://relay.test:28080';
const RELAY_SECRET = 'test-secret-1234567890';
const RAI1 = 'MTowOjE5OkQ0OToxNDUwOjEzRTo4MjAwMDA6MDowOjA6'; // base64url of 1:0:19:D49:1450:13E:820000:0:0:0:

function receiver(array $attributes = []): Source
{
    return Source::create(array_merge([
        'name' => 'Octagon', 'type' => 'receiver', 'url' => RELAY, 'relay_secret' => RELAY_SECRET,
        'check_streams' => false, 'skip_vod' => false,
    ], $attributes));
}

function receiverChannel(Source $source, User $user): Channel
{
    return subscribedChannel($user, ['name' => 'Rai 1 HD', 'source_id' => $source->id, 'stream_url' => RELAY.'/s/'.RAI1.'.ts']);
}

it('signs exactly like the relay (nebotv-relay.py)', function () {
    // Vector produced by sign() in relay/nebotv-relay.py
    expect(ReceiverService::sign(RELAY_SECRET, RAI1, 1800000000, '1'))->toBe('c4587a499d69f117e64357936adc4e45');
});

it('sends receiver channels to a signed, expiring relay link', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $channel = receiverChannel(receiver(['transcode' => true]), $user);

    $url = app(ReceiverService::class)->playUrl($channel);

    $expires = now()->addMinutes(config('iptv.receiver_link_minutes'))->timestamp;
    expect($url)->toBe(RELAY.'/s/'.RAI1.'.ts?e='.$expires.'&x=1&s='.ReceiverService::sign(RELAY_SECRET, RAI1, $expires, '1'));
});

it('leaves other channels alone', function () {
    $user = User::factory()->create();
    $channel = subscribedChannel($user);

    expect(app(ReceiverService::class)->playUrl($channel))->toBe($channel->stream_url);
});

it('redirects /play and the Xtream /live to the signed link', function () {
    $user = User::factory()->create(['username' => 'tv1', 'password' => bcrypt('secret'), 'max_connections' => 2]);
    $channel = receiverChannel(receiver(), $user);
    $token = app(\App\Services\TokenService::class)->generateToken($user)->token;

    $location = $this->get(route('play', ['channel_id' => $channel->id, 'token' => $token]))->assertRedirect()->headers->get('Location');
    expect($location)->toStartWith(RELAY.'/s/'.RAI1.'.ts?e=')->toContain('&x=0&s=');

    expect($this->get("/live/tv1/secret/{$channel->id}.ts")->headers->get('Location'))->toStartWith(RELAY.'/s/'.RAI1.'.ts?e=');
});

it('imports the relay playlist with the secret and the receiver categories', function () {
    Http::fake([RELAY.'/playlist.m3u*' => fn () => Http::response(implode("\n", [
        '#EXTM3U',
        '#EXTINF:-1 tvg-id="1:0:19:D49:1450:13E:820000:0:0:0:" group-title="RAI",Rai 1 HD',
        RELAY.'/s/'.RAI1.'.ts',
    ]))]);
    $source = receiver(['bouquet_id' => Bouquet::create(['name' => 'Italia'])->id]);

    app(SourceSyncService::class)->sync($source);

    Http::assertSent(fn ($request) => $request->url() === RELAY.'/playlist.m3u?key='.RELAY_SECRET);
    expect(Channel::sole())->name->toBe('Rai 1 HD')->category_id->toBe(Category::where('name', 'RAI')->value('id'))
        ->and(Bouquet::where('name', 'Italia')->first()->channels()->count())->toBe(1);
});

it('never stores the secret in an import error', function () {
    Http::fake([RELAY.'/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 7: Failed to connect to '.RELAY.'/playlist.m3u?key='.RELAY_SECRET)]);
    $source = receiver();

    expect(fn () => app(SourceSyncService::class)->sync($source))->toThrow(\Illuminate\Http\Client\ConnectionException::class);

    expect($source->fresh()->last_error)->not->toContain(RELAY_SECRET)->toContain('***');
});

it('never checks receiver channels with the stream check', function () {
    $user = User::factory()->create();
    receiverChannel(receiver(['check_streams' => true]), $user);

    expect(app(StreamCheckService::class)->checkableQuery()->count())->toBe(0);
});

it('checks the relay and alerts when it goes offline and comes back', function () {
    $online = true;
    Http::fake([RELAY.'/status*' => function () use (&$online) {
        return $online ? Http::response(['ok' => true, 'box' => true]) : Http::response('', 502);
    }]);
    $source = receiver();
    $service = app(ReceiverService::class);

    expect($service->check($source))->toBeTrue();
    Http::assertSent(fn ($request) => $request->url() === RELAY.'/status?key='.RELAY_SECRET);
    expect(StreamAlert::count())->toBe(0);

    $online = false;
    $service->check($source->fresh());
    $service->check($source->fresh());
    $online = true;
    $service->check($source->fresh());

    expect(StreamAlert::orderBy('id')->pluck('type')->all())->toBe(['receiver_down', 'receiver_up'])
        ->and(StreamAlert::first()->message())->toBe('Receiver Octagon is offline')
        ->and($source->fresh())->receiver_online->toBeTrue()->receiver_checked_at->not->toBeNull();
});

it('alerts when a new receiver is offline on its first check', function () {
    Http::fake([RELAY.'/status*' => Http::response('', 502)]);

    app(ReceiverService::class)->check(receiver());

    expect(StreamAlert::sole()->type)->toBe('receiver_down');
});

it('checks receivers every five minutes', function () {
    $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command ?? '', 'iptv:check-receivers'));

    expect($event?->expression)->toBe('*/5 * * * *');
});

describe('admin', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->admin()->create());
        Queue::fake();
    });

    it('adds a receiver source', function () {
        $this->get(route('admin.sources.create'))->assertOk()->assertSee('Satellite receiver');

        $this->post(route('admin.sources.store'), [
            'name' => 'Octagon', 'type' => 'receiver', 'relay_url' => RELAY.'/', 'relay_secret' => RELAY_SECRET,
            'transcode' => '1', 'check_streams' => '1', 'bouquet_id' => '',
        ])->assertRedirect(route('admin.sources.index'));

        $source = Source::sole();
        expect($source)->type->toBe('receiver')->url->toBe(RELAY)->relay_secret->toBe(RELAY_SECRET)
            ->transcode->toBeTrue()->check_streams->toBeFalse()
            ->and($source->getRawOriginal('relay_secret'))->not->toBe(RELAY_SECRET); // encrypted at rest
    });

    it('requires the relay address and a long enough secret', function () {
        $this->post(route('admin.sources.store'), ['name' => 'X', 'type' => 'receiver', 'relay_secret' => 'short'])
            ->assertSessionHasErrors(['relay_url', 'relay_secret']);
    });

    it('keeps the secret when the edit form leaves it empty', function () {
        $source = receiver();

        $this->put(route('admin.sources.update', $source), ['name' => 'Box', 'type' => 'receiver', 'relay_url' => RELAY, 'relay_secret' => ''])
            ->assertRedirect();

        expect($source->fresh())->name->toBe('Box')->relay_secret->toBe(RELAY_SECRET);
    });

    it('shows whether the receiver is online', function () {
        receiver(['receiver_online' => false]);

        $this->get(route('admin.sources.index'))->assertOk()->assertSee('receiver offline');
    });
});
