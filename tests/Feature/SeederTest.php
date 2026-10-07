<?php

use App\Jobs\SyncSource;
use App\Models\Bouquet;
use App\Models\Category;
use App\Models\Source;
use App\Models\User;
use App\Services\SourceSyncService;
use Database\Seeders\DefaultSourcesSeeder;
use Database\Seeders\SampleDataSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake();
    Storage::fake();
});

it('seeds the admin via the default seeder and is idempotent', function () {
    $this->seed();
    $this->seed();

    expect(User::where('role', 'admin')->count())->toBe(1);
});

it('seeds the sample data', function () {
    $this->seed(SampleDataSeeder::class);

    expect(User::where('role', 'user')->count())->toBe(3);
});

it('seeds the default sources with bouquets and queues their imports', function () {
    $this->seed();

    $count = count(DefaultSourcesSeeder::SOURCES);
    expect(Source::count())->toBe($count)
        ->and(Source::whereNull('bouquet_id')->count())->toBe(0)
        ->and(Source::where('status', Source::STATUS_QUEUED)->count())->toBe($count)
        ->and(Bouquet::where('name', 'Macedonia TV')->exists())->toBeTrue();
    Queue::assertPushed(SyncSource::class, $count);

    $file = Source::where('name', 'Macedonia – TV')->first();
    expect($file->type)->toBe('file')->and($file->auto_sync)->toBeFalse();
    Storage::assertExists($file->file_path);
});

it('does not seed default sources again once any source exists', function () {
    $this->seed();
    Source::where('name', 'iptv-org – News')->delete();

    $this->seed();

    expect(Source::where('name', 'iptv-org – News')->exists())->toBeFalse();
});

it('re-adds missing default sources when the seeder is run directly', function () {
    $this->seed(DefaultSourcesSeeder::class);
    Source::where('name', 'iptv-org – News')->delete();

    $this->seed(DefaultSourcesSeeder::class);

    expect(Source::count())->toBe(count(DefaultSourcesSeeder::SOURCES));
});

it('imports the bundled Macedonian lists into MK categories and bouquets', function () {
    $this->seed(DefaultSourcesSeeder::class);

    Source::where('type', 'file')->get()->each(fn ($source) => app(SourceSyncService::class)->sync($source));

    expect(Category::whereIn('name', ['MK | National', 'MK | Regional', 'MK | Music'])->count())->toBe(3)
        ->and(Bouquet::where('name', 'Macedonia TV')->first()->channels()->count())->toBe(8)
        ->and(Bouquet::where('name', 'Macedonia Music')->first()->channels()->count())->toBe(6);
});
