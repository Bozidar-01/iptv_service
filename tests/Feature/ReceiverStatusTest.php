<?php

use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\EpgSource;
use App\Models\Source;
use App\Models\User;
use App\Services\ReceiverStatusService;
use App\Services\ReceiverTidyService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->source = Source::create(['name' => 'Octagon', 'type' => 'receiver', 'url' => 'http://relay.test:28080', 'relay_secret' => 'secret-1234567890123', 'channel_count' => 6]);
    $this->rai = '1:0:19:D49:1450:13E:820000:0:0:0:';
});

function tidyChannel(Source $source, string $name, string $ref, array $attrs = []): Channel
{
    return Channel::create(array_merge(['name' => $name, 'stream_url' => 'http://relay.test/s/x.ts', 'tvg_id' => $ref, 'is_active' => true, 'source_id' => $source->id], $attrs));
}

describe('the status', function () {
    it('reads the relay: tuners, satellite, cable and the latest problems with channel names', function () {
        tidyChannel($this->source, 'Rai 1 HD', $this->rai);
        refakeHttp(['*relay.test*' => fn () => Http::response([
            'ok' => true, 'version' => '1.2', 'active_channels' => 2, 'active_tuners' => 1, 'max_streams' => 2, 'single_cable' => true, 'satellites' => [130],
            'recent_events' => [['t' => 1000, 'kind' => 'refused', 'ref' => $this->rai, 'reason' => 'All tuners are busy'], ['t' => 2000, 'kind' => 'no_video', 'ref' => '1:0:1:1:1:1:C00000:0:0:0:', 'reason' => 'x']],
        ])]);

        $status = app(ReceiverStatusService::class)->status($this->source);

        expect($status)->online->toBeTrue()->version->toBe('1.2')->active_tuners->toBe(1)->max_streams->toBe(2)->single_cable->toBeTrue()->satellites->toBe(['13.0° E'])
            ->and($status['events'][0])->toMatchArray(['kind' => 'no_video', 'when' => 2000, 'channel' => '1:0:1:1:1:1:C00000:0:0:0:'])
            ->and($status['events'][1])->toMatchArray(['kind' => 'refused', 'channel' => 'Rai 1 HD']);
    });

    it('names satellites east and west', function () {
        expect(ReceiverStatusService::satelliteName(130))->toBe('13.0° E')->and(ReceiverStatusService::satelliteName(192))->toBe('19.2° E')->and(ReceiverStatusService::satelliteName(3550))->toBe('5.0° W');
    });

    it('says why it can not read the relay', function () {
        refakeHttp(['*relay.test*' => fn () => Http::response('nope', 403)]);
        expect(app(ReceiverStatusService::class)->status($this->source))->online->toBeFalse()->error->toContain('secret');

        Cache::flush();
        refakeHttp(['*relay.test*' => fn () => throw new ConnectionException('down')]);
        expect(app(ReceiverStatusService::class)->status($this->source))->online->toBeFalse()->error->toContain('did not answer');
    });

    it('copes with an older relay and keeps the answer for a few seconds', function () {
        refakeHttp(['*relay.test*' => fn () => Http::response(['ok' => true, 'version' => '1.0', 'active_channels' => 0, 'max_streams' => 2])]);

        $first = app(ReceiverStatusService::class)->status($this->source);
        expect($first)->has_events->toBeFalse()->single_cable->toBeNull()->active_tuners->toBeNull();

        refakeHttp(['*relay.test*' => fn () => throw new Exception('should not be asked again')]);
        expect(app(ReceiverStatusService::class)->status($this->source))->toBe($first);
    });
});

describe('the page', function () {
    it('shows the status and is for admins and receivers only', function () {
        refakeHttp(['*relay.test*' => fn () => Http::response(['ok' => true, 'version' => '1.2', 'active_channels' => 0, 'active_tuners' => 0, 'max_streams' => 2, 'single_cable' => true, 'satellites' => [], 'recent_events' => []])]);

        $this->actingAs($this->admin)->get(route('admin.receiver.show', $this->source))->assertOk()->assertSee('Online')->assertSee('one cable: one satellite at a time')->assertSee('No problems since the relay started.');
        $this->actingAs($this->admin)->get(route('admin.sources.index'))->assertSee(route('admin.receiver.show', $this->source), false);

        $plain = Source::create(['name' => 'Plain', 'type' => 'url', 'url' => 'http://a.test/a.m3u']);
        $this->actingAs($this->admin)->get(route('admin.receiver.show', $plain))->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'reseller']))->get(route('admin.receiver.show', $this->source))->assertForbidden();
    });

    it('tells an old relay to be updated', function () {
        refakeHttp(['*relay.test*' => fn () => Http::response(['ok' => true, 'version' => '1.0', 'active_channels' => 0, 'max_streams' => 2])]);

        $this->actingAs($this->admin)->get(route('admin.receiver.show', $this->source))->assertSee('too old to report problems');
    });
});

describe('shortening the list', function () {
    beforeEach(function () {
        $guide = EpgSource::create(['name' => 'Italy', 'url' => 'http://g.test/epg-it.xml']);
        tidyChannel($this->source, 'Rai 1 HD', $this->rai, ['epg_source_id' => $guide->id, 'epg_id' => 'Rai1.it']);
        tidyChannel($this->source, 'Radio Zeta', '1:0:2:1:1:1:C00000:0:0:0:');
        tidyChannel($this->source, '(RADIO) Marca', '1:0:1:2:2:1:C00000:0:0:0:');
        tidyChannel($this->source, 'La 1 Extremadura', '1:0:1:3:3:1:0:0:0:0:http%3a//localhost%3a9999/abertis/pid1:La 1');
        tidyChannel($this->source, 'No Guide TV', '1:0:19:4:4:1:C00000:0:0:0:');
        tidyChannel($this->source, 'UHD Film', '1:0:1F:5:5:1:C00000:0:0:0:');
        tidyChannel($this->source, 'Off already', '1:0:2:9:9:1:C00000:0:0:0:', ['is_active' => false]);
        $other = Source::create(['name' => 'Other', 'type' => 'url', 'url' => 'http://o.test/o.m3u']);
        Channel::create(['name' => 'Elsewhere', 'stream_url' => 'http://o.test/1', 'tvg_id' => '1:0:2:7:7:1:0:0:0:0:', 'is_active' => true, 'source_id' => $other->id]);
    });

    it('counts what each kind would switch off, enabled channels of this source only', function () {
        expect(app(ReceiverTidyService::class)->counts($this->source))->toBe(['radio' => 2, 'internal' => 1, 'no_guide' => 5, 'enabled' => 6]);
    });

    it('switches off the chosen kinds, once, without deleting', function () {
        $this->actingAs($this->admin)->post(route('admin.receiver.tidy', $this->source), ['criteria' => ['radio', 'internal']])->assertSessionHas('success', '3 channels switched off.');

        expect(Channel::where('source_id', $this->source->id)->where('is_active', true)->pluck('name')->sort()->values()->all())->toBe(['No Guide TV', 'Rai 1 HD', 'UHD Film'])
            ->and(Channel::count())->toBe(8)->and(Channel::where('name', 'Elsewhere')->value('is_active'))->toBeTrue()
            ->and(AuditLog::latest('id')->first()->action)->toBe(AuditLog::CHANNELS_TIDIED);

        $this->actingAs($this->admin)->post(route('admin.receiver.tidy', $this->source), ['criteria' => ['radio', 'internal']])->assertSessionHas('success', '0 channels switched off.');
    });

    it('can switch off the channels without a guide, keeping the ones that have one', function () {
        $this->actingAs($this->admin)->post(route('admin.receiver.tidy', $this->source), ['criteria' => ['no_guide']]);

        expect(Channel::where('source_id', $this->source->id)->where('is_active', true)->pluck('name')->all())->toBe(['Rai 1 HD']);
    });

    it('needs a choice, and knows only the listed kinds', function () {
        $this->actingAs($this->admin)->post(route('admin.receiver.tidy', $this->source), [])->assertSessionHasErrors('criteria');
        $this->actingAs($this->admin)->post(route('admin.receiver.tidy', $this->source), ['criteria' => ['everything']])->assertSessionHasErrors('criteria.0');
        $this->actingAs(User::factory()->create(['role' => 'reseller']))->post(route('admin.receiver.tidy', $this->source), ['criteria' => ['radio']])->assertForbidden();
    });
});
