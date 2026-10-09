<?php

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Channel;
use App\Models\CleanupRule;
use App\Models\Source;
use App\Models\User;
use App\Services\CleanupRuleService;
use App\Services\SourceSyncService;
use Illuminate\Support\Facades\Http;

function rule(string $kind, string $match, string $pattern, ?string $replacement = null, ?int $sourceId = null, int $order = 0): CleanupRule
{
    return CleanupRule::create(['kind' => $kind, 'match' => $match, 'pattern' => $pattern, 'replacement' => $replacement, 'source_id' => $sourceId, 'enabled' => true, 'sort_order' => $order]);
}

function cleanChannel(string $name, ?string $category = null, ?Source $source = null): Channel
{
    $categoryId = $category ? Category::firstOrCreate(['name' => $category])->id : null;

    return Channel::create(['name' => $name, 'stream_url' => 'http://x.test/'.md5($name.microtime()), 'is_active' => true, 'category_id' => $categoryId, 'source_id' => $source?->id]);
}

describe('one rule', function () {
    it('changes a channel name by contains, equals and regex', function () {
        expect(rule('channel_name', 'contains', ' HD', '')->rewriteName('Rai 1 HD Sicilia'))->toBe('Rai 1 Sicilia')
            ->and(rule('channel_name', 'equals', 'rai uno', 'Rai 1')->rewriteName('Rai Uno'))->toBe('Rai 1')
            ->and(rule('channel_name', 'equals', 'rai uno', 'Rai 1')->rewriteName('Rai Uno 2'))->toBe('Rai Uno 2')
            ->and(rule('channel_name', 'regex', '\s*\[[^\]]*\]\s*$', '')->rewriteName('24h HD Canarias [301]'))->toBe('24h HD Canarias')
            ->and(rule('channel_name', 'regex', '^(\w+): (.*)$', '$2 ($1)')->rewriteName('IT: Rai 1'))->toBe('Rai 1 (IT)');
    });

    it('never makes a name empty', function () {
        expect(rule('channel_name', 'regex', '.*', '')->rewriteName('Rai 1'))->toBe('Rai 1');
    });

    it('turns a whole category into another with contains / equals, and edits it with a regex', function () {
        expect(rule('category', 'contains', '(TV) 11222', 'Satellite')->rewriteCategory('(TV) 11222 H 30000 3/4'))->toBe('Satellite')
            ->and(rule('category', 'equals', 'sports hd', 'Sports')->rewriteCategory('Sports HD'))->toBe('Sports')
            ->and(rule('category', 'regex', '^IT\s*-\s*', '')->rewriteCategory('IT - News'))->toBe('News')
            ->and(rule('category', 'contains', 'xyz', 'Other')->rewriteCategory('News'))->toBe('News');
    });

    it('checks the pattern', function () {
        expect(CleanupRule::patternIsValid('regex', '^(a'))->toBeFalse()
            ->and(CleanupRule::patternIsValid('regex', '^(a)'))->toBeTrue()
            ->and(CleanupRule::patternIsValid('contains', '(a'))->toBeTrue()
            ->and(CleanupRule::patternIsValid('contains', ''))->toBeFalse();
    });
});

describe('the rules together', function () {
    it('runs in order, only the ones for the source, and skips disabled ones', function () {
        $a = Source::create(['name' => 'A', 'type' => 'url', 'url' => 'http://a.test/a.m3u']);
        $b = Source::create(['name' => 'B', 'type' => 'url', 'url' => 'http://b.test/b.m3u']);
        rule('channel_name', 'contains', 'X', 'Y', null, 1);
        rule('channel_name', 'contains', 'Y', 'Z', null, 2);
        rule('channel_name', 'contains', 'Q', 'R', $a->id, 3);
        CleanupRule::create(['kind' => 'channel_name', 'match' => 'contains', 'pattern' => 'Z', 'replacement' => 'NEVER', 'enabled' => false, 'sort_order' => 4]);

        $service = app(CleanupRuleService::class);

        expect($service->for($a->id)->name('X Q'))->toBe('Z R')
            ->and($service->for($b->id)->name('X Q'))->toBe('Z Q');
    });

    it('lets a rule by channel name decide the category', function () {
        rule('category', 'contains', 'Grp', 'Satellite');
        rule('channel_category', 'contains', 'sport', 'Sports');
        $rules = app(CleanupRuleService::class)->for(null);

        expect($rules->category('Grp 1'))->toBe('Satellite')
            ->and($rules->channelCategory('Sky Sport 1'))->toBe('Sports')
            ->and($rules->channelCategory('Rai 1'))->toBeNull()
            ->and($rules->category(null))->toBeNull()
            ->and($rules->category('  '))->toBe('  ');
    });
});

describe('on import', function () {
    function importPlaylist(Source $source, string $m3u): void
    {
        refakeHttp(['*' => fn () => Http::response($m3u)]);
        app(SourceSyncService::class)->sync($source);
    }

    it('stores cleaned names and categories, and keeps the channel when a rule is added later', function () {
        $source = Source::create(['name' => 'Sat', 'type' => 'url', 'url' => 'http://sat.test/a.m3u']);
        $m3u = "#EXTM3U\n#EXTINF:-1 group-title=\"(TV) 11222 H 30000\",24h HD Canarias [301]\nhttp://s.test/1\n#EXTINF:-1 group-title=\"(TV) 11222 H 30000\",Sky Sport 1\nhttp://s.test/2\n#EXTINF:-1 group-title=\"News\",Other\nhttp://s.test/3\n";

        importPlaylist($source, $m3u);
        $original = Channel::where('source_id', $source->id)->pluck('id', 'external_key');
        expect(Channel::where('name', '24h HD Canarias [301]')->exists())->toBeTrue();

        rule('channel_name', 'regex', '\s*\[[^\]]*\]\s*$', '');
        rule('category', 'regex', '^\(TV\)\s+\d+.*$', 'Satellite');
        rule('channel_category', 'contains', 'sport', 'Sports');
        importPlaylist($source, $m3u);

        $channels = Channel::where('source_id', $source->id)->with('category')->get()->keyBy('name');
        expect($channels)->toHaveCount(3)
            ->and($channels['24h HD Canarias']->category->name)->toBe('Satellite')
            ->and($channels['Sky Sport 1']->category->name)->toBe('Sports')
            ->and($channels['Other']->category->name)->toBe('News')
            // the same channels as before: the rules do not change their identity
            ->and(Channel::where('source_id', $source->id)->pluck('id', 'external_key')->all())->toBe($original->all());
    });
});

describe('on the channels we have', function () {
    it('previews without changing anything', function () {
        cleanChannel('A [1]');
        cleanChannel('B');

        $preview = app(CleanupRuleService::class)->preview(collect([new CleanupRule(['kind' => 'channel_name', 'match' => 'regex', 'pattern' => '\s*\[[^\]]*\]$', 'replacement' => ''])]));

        expect($preview)->toBe(['names' => 1, 'categories' => 0, 'examples' => [['A [1]', 'A']]])
            ->and(Channel::where('name', 'A [1]')->exists())->toBeTrue();
    });

    it('applies the saved rules, moves channels, and deletes categories that end up empty', function () {
        cleanChannel('A [1]', '(TV) 11222 H');
        cleanChannel('Sky Sport 1', '(TV) 11222 H');
        cleanChannel('Plain', 'Kept');
        rule('channel_name', 'regex', '\s*\[[^\]]*\]$', '');
        rule('category', 'regex', '^\(TV\).*$', 'Satellite');
        rule('channel_category', 'contains', 'sport', 'Sports');
        CleanupRule::create(['kind' => 'channel_name', 'match' => 'contains', 'pattern' => 'Plain', 'replacement' => 'Off', 'enabled' => false, 'sort_order' => 9]);

        $result = app(CleanupRuleService::class)->applyToExisting();

        expect($result)->toBe(['names' => 1, 'categories' => 2])
            ->and(Channel::with('category')->get()->mapWithKeys(fn ($c) => [$c->name => $c->category?->name])->all())->toBe(['A' => 'Satellite', 'Sky Sport 1' => 'Sports', 'Plain' => 'Kept'])
            ->and(Category::pluck('name')->sort()->values()->all())->toBe(['Kept', 'Satellite', 'Sports']);

        // Running it again changes nothing
        expect(app(CleanupRuleService::class)->applyToExisting())->toBe(['names' => 0, 'categories' => 0]);
    });

    it('applies the rules of a source only to its own channels', function () {
        $a = Source::create(['name' => 'A', 'type' => 'url', 'url' => 'http://a.test/a.m3u']);
        $b = Source::create(['name' => 'B', 'type' => 'url', 'url' => 'http://b.test/b.m3u']);
        cleanChannel('Same x', null, $a);
        cleanChannel('Same x', null, $b);
        rule('channel_name', 'contains', ' x', '', $a->id);

        app(CleanupRuleService::class)->applyToExisting();

        expect(Channel::where('source_id', $a->id)->value('name'))->toBe('Same')->and(Channel::where('source_id', $b->id)->value('name'))->toBe('Same x');
    });

    it('sorts the Satellite group by genre on import and when applied, and leaves other groups alone', function () {
        $service = app(CleanupRuleService::class);
        foreach (['satellite', 'genres'] as $pack) {
            $service->addPack($pack);
        }
        cleanChannel('Sky Sport 1', '(TV) 11222 H 30000');
        cleanChannel('Rai News 24', '(TV) 11302 V 27500');
        cleanChannel('Mystery', '(TV) 11302 V 27500');
        cleanChannel('Sky Sport 2', 'Albania');

        $service->applyToExisting();

        expect(Channel::with('category')->get()->mapWithKeys(fn ($c) => [$c->name => $c->category->name])->all())
            ->toBe(['Sky Sport 1' => 'Sports', 'Rai News 24' => 'News', 'Mystery' => 'Satellite', 'Sky Sport 2' => 'Albania']);
    });

    it('adds a ready-made set once', function () {
        $service = app(CleanupRuleService::class);

        expect($service->addPack('genres'))->toBe(6)->and($service->addPack('genres'))->toBe(0)->and($service->addPack('tags'))->toBe(1);

        $rules = $service->for(null);
        // The genres only sort the Satellite group; other categories are left as the playlist has them
        expect($rules->channelCategory('Sky Sport Bundesliga 6', 'Satellite'))->toBe('Sports')
            ->and($rules->channelCategory('Rai News 24', 'satellite '))->toBe('News')
            ->and($rules->channelCategory('Disney Channel', 'Satellite'))->toBe('Kids')
            ->and($rules->channelCategory('Disney Channel', 'Kids TV'))->toBeNull()
            ->and($rules->channelCategory('Disney Channel'))->toBeNull()
            ->and($rules->name('24h HD Canarias [301]'))->toBe('24h HD Canarias');
    });
});

describe('the page', function () {
    beforeEach(fn () => $this->admin = User::factory()->admin()->create());

    it('is for admins only and is in the menu', function () {
        $this->actingAs($this->admin)->get(route('admin.cleanup.index'))->assertOk()->assertSee('Cleanup rules')->assertSee('Remove tags in square brackets');
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertSee(route('admin.cleanup.index'), false);

        $this->actingAs(User::factory()->create(['role' => 'reseller']))->get(route('admin.cleanup.index'))->assertForbidden();
        auth()->logout();
        $this->get(route('admin.cleanup.index'))->assertRedirect(route('login'));
    });

    it('saves a rule, validates it, and previews without saving', function () {
        $this->actingAs($this->admin);
        cleanChannel('A [1]');

        $this->post(route('admin.cleanup.store'), ['kind' => 'channel_name', 'match' => 'regex', 'pattern' => '(', 'replacement' => ''])->assertSessionHasErrors('pattern');
        $this->post(route('admin.cleanup.store'), ['kind' => 'category', 'match' => 'contains', 'pattern' => 'x'])->assertSessionHasErrors('replacement');
        $this->post(route('admin.cleanup.store'), ['kind' => 'channel_category', 'match' => 'contains', 'pattern' => 'sport', 'replacement' => 'Sports', 'when_category' => 'Satellite']);
        expect(CleanupRule::first()->when_category)->toBe('Satellite');
        $this->post(route('admin.cleanup.store'), ['kind' => 'category', 'match' => 'contains', 'pattern' => 'x', 'replacement' => 'Y', 'when_category' => 'Ignored']);
        expect(CleanupRule::latest('id')->first()->when_category)->toBeNull();
        CleanupRule::query()->delete();
        $this->post(route('admin.cleanup.store'), ['kind' => 'nonsense', 'match' => 'contains', 'pattern' => 'x'])->assertSessionHasErrors('kind');
        expect(CleanupRule::count())->toBe(0);

        $this->post(route('admin.cleanup.store'), ['kind' => 'channel_name', 'match' => 'regex', 'pattern' => '\s*\[[^\]]*\]$', 'replacement' => '', 'preview' => 1])
            ->assertSessionHas('preview', fn ($p) => $p['names'] === 1 && $p['examples'] === [['A [1]', 'A']]);
        expect(CleanupRule::count())->toBe(0);

        $this->post(route('admin.cleanup.store'), ['kind' => 'channel_name', 'match' => 'regex', 'pattern' => '\s*\[[^\]]*\]$', 'replacement' => ''])->assertSessionHas('success');
        expect(CleanupRule::count())->toBe(1);
    });

    it('toggles, deletes, adds packs and applies, with an audit entry', function () {
        $this->actingAs($this->admin);
        $rule = rule('channel_name', 'regex', '\s*\[[^\]]*\]$', '');
        cleanChannel('A [1]');

        $this->post(route('admin.cleanup.toggle', $rule));
        expect($rule->fresh()->enabled)->toBeFalse();
        $this->post(route('admin.cleanup.toggle', $rule));
        expect($rule->fresh()->enabled)->toBeTrue();

        $this->post(route('admin.cleanup.pack', 'genres'))->assertSessionHas('success', '6 rules added.');
        $this->post(route('admin.cleanup.pack', 'genres'))->assertSessionHas('error');
        $this->post(route('admin.cleanup.pack', 'nope'))->assertNotFound();

        $this->post(route('admin.cleanup.apply'))->assertSessionHas('success');
        expect(Channel::first())->name->toBe('A')
            ->and(AuditLog::latest('id')->first()->action)->toBe(AuditLog::CLEANUP_APPLIED);

        $this->delete(route('admin.cleanup.destroy', $rule))->assertSessionHas('success');
        expect(CleanupRule::find($rule->id))->toBeNull();
    });
});
