<?php

use App\Jobs\SyncEpgSource;
use App\Models\EpgSource;
use App\Models\Source;
use App\Models\User;
use App\Services\EpgImportService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->source = Source::create(['name' => 'Octagon', 'type' => 'receiver', 'url' => 'http://relay.test:28080/', 'relay_secret' => 'secret-1234567890123']);
});

it('adds the receiver\'s guide once, without the relay key in its address, and imports it', function () {
    Queue::fake();
    Http::fake(['relay.test:28080/status*' => fn () => Http::response(['ok' => true, 'version' => '1.3'])]);

    $this->actingAs($this->admin)->post(route('admin.receiver.guide', $this->source))->assertSessionHas('success');
    $this->post(route('admin.receiver.guide', $this->source));

    $guide = EpgSource::sole();
    expect($guide)->url->toBe('http://relay.test:28080/epg.xml')->receiver_source_id->toBe($this->source->id)
        ->and($guide->downloadUrl())->toBe('http://relay.test:28080/epg.xml?key=secret-1234567890123');
    // The second press finds an import already waiting
    Queue::assertPushed(SyncEpgSource::class, 1);

    $this->get(route('admin.receiver.show', $this->source))->assertOk()->assertSee('TV guide from the receiver')->assertSee('Import the guide now');
});

it('downloads the guide with the key and never stores the key in an error', function () {
    $guide = EpgSource::create(['name' => 'Box', 'url' => 'http://relay.test:28080/epg.xml', 'receiver_source_id' => $this->source->id]);
    Http::fake(['relay.test:28080/epg.xml*' => fn () => throw new RuntimeException('cURL error 7: http://relay.test:28080/epg.xml?key=secret-1234567890123 refused')]);

    expect(fn () => app(EpgImportService::class)->sync($guide))->toThrow(RuntimeException::class);

    expect($guide->fresh()->last_error)->toContain('cURL error 7')->not->toContain('secret-1234567890123');

    refakeHttp(['relay.test:28080/epg.xml*' => fn () => Http::response('', 500)]);
    expect(fn () => app(EpgImportService::class)->sync($guide->fresh()))->toThrow(RuntimeException::class);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'key=secret-1234567890123'));
});

it('says when the relay is too old for it', function () {
    Http::fake(['relay.test:28080/status*' => fn () => Http::response(['ok' => true, 'version' => '1.2'])]);

    $this->actingAs($this->admin)->get(route('admin.receiver.show', $this->source))->assertSee('This needs relay 1.3 or newer');
});

it('is only for receiver sources', function () {
    $plain = Source::create(['name' => 'Plain', 'type' => 'url', 'url' => 'http://a.test/a.m3u']);

    $this->actingAs($this->admin)->post(route('admin.receiver.guide', $plain))->assertNotFound();
});
