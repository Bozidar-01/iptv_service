<?php

use App\Jobs\SyncSource;
use App\Models\Bouquet;
use App\Models\Channel;
use App\Models\Source;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
    Queue::fake();
});

it('adds a URL source, creates its bouquet and queues an import', function () {
    $this->post(route('admin.sources.store'), [
        'name' => 'My provider',
        'type' => 'url',
        'url' => 'http://provider.test/get.php?type=m3u_plus',
        'bouquet_id' => 'new',
        'skip_vod' => '1',
        'auto_sync' => '0',
    ])->assertRedirect(route('admin.sources.index'));

    $source = Source::firstOrFail();
    expect($source)
        ->status->toBe(Source::STATUS_QUEUED)
        ->skip_vod->toBeTrue()
        ->auto_sync->toBeFalse()
        ->and($source->bouquet->name)->toBe('My provider');

    Queue::assertPushed(SyncSource::class, fn ($job) => $job->source->is($source));
});

it('adds a file source', function () {
    Storage::fake();

    $this->post(route('admin.sources.store'), [
        'name' => 'Upload',
        'type' => 'file',
        'file' => UploadedFile::fake()->createWithContent('list.m3u', "#EXTM3U\n#EXTINF:-1,A\nhttp://x.test/a\n"),
        'bouquet_id' => '',
    ])->assertRedirect(route('admin.sources.index'));

    $source = Source::firstOrFail();
    expect($source->url)->toBeNull()->and($source->bouquet_id)->toBeNull();
    Storage::assertExists($source->file_path);
});

it('validates the source form', function () {
    $this->post(route('admin.sources.store'), ['name' => '', 'type' => 'url', 'url' => 'not a url'])
        ->assertSessionHasErrors(['name', 'url']);

    $this->post(route('admin.sources.store'), ['name' => 'F', 'type' => 'file'])
        ->assertSessionHasErrors(['file']);

    $this->post(route('admin.sources.store'), ['name' => 'B', 'type' => 'url', 'url' => 'http://x.test', 'bouquet_id' => '999'])
        ->assertSessionHasErrors(['bouquet_id']);
});

it('queues a manual sync but not twice', function () {
    $source = Source::create(['name' => 'P', 'type' => 'url', 'url' => 'http://x.test']);

    $this->post(route('admin.sources.sync', $source))->assertSessionHas('success');
    $this->post(route('admin.sources.sync', $source))->assertSessionHas('error');

    Queue::assertPushed(SyncSource::class, 1);
});

it('updates a source', function () {
    $source = Source::create(['name' => 'P', 'type' => 'url', 'url' => 'http://x.test']);
    $bouquet = Bouquet::create(['name' => 'Pack']);

    $this->put(route('admin.sources.update', $source), [
        'name' => 'Renamed', 'type' => 'url', 'url' => 'http://y.test', 'bouquet_id' => $bouquet->id, 'skip_vod' => '0', 'auto_sync' => '1',
    ])->assertRedirect(route('admin.sources.index'));

    expect($source->fresh())
        ->name->toBe('Renamed')
        ->url->toBe('http://y.test')
        ->bouquet_id->toBe($bouquet->id)
        ->skip_vod->toBeFalse();
});

it('deletes a source with its channels', function () {
    $source = Source::create(['name' => 'P', 'type' => 'url', 'url' => 'http://x.test']);
    Channel::create(['name' => 'Imported', 'stream_url' => 'http://x.test/1', 'source_id' => $source->id, 'external_key' => 'k']);
    $manual = Channel::create(['name' => 'Manual', 'stream_url' => 'http://x.test/2']);

    $this->delete(route('admin.sources.destroy', $source))->assertRedirect(route('admin.sources.index'));

    expect(Source::count())->toBe(0)
        ->and(Channel::pluck('id')->all())->toBe([$manual->id]);
});

it('shows sources on the index and edit pages', function () {
    $source = Source::create(['name' => 'Visible provider', 'type' => 'url', 'url' => 'http://x.test', 'status' => 'failed', 'last_error' => 'Download failed with HTTP 500.']);

    $this->get(route('admin.sources.index'))->assertOk()->assertSee('Visible provider')->assertSee('Download failed with HTTP 500.');
    $this->get(route('admin.sources.edit', $source))->assertOk()->assertSee('Last sync failed');
});
