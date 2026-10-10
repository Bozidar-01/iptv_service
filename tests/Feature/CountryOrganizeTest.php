<?php

use App\Models\Category;
use App\Models\Channel;
use App\Models\CleanupRule;
use App\Models\EpgSource;
use App\Models\Source;
use App\Models\User;
use App\Services\CountryOrganizeService;
use App\Services\EpgGuideCatalog;
use App\Services\SourceSyncService;
use App\Support\Countries;
use Illuminate\Support\Facades\Http;

function orgChannel(string $name, ?string $group = null, array $attrs = []): Channel
{
    $category = $group ? Category::firstOrCreate(['name' => $group])->id : null;

    return Channel::create(array_merge(['name' => $name, 'stream_url' => 'http://x.test/'.md5($name.microtime()), 'is_active' => true, 'category_id' => $category], $attrs));
}

function orgInfo(array $over = []): array
{
    return array_merge(['name' => 'Some Channel', 'tvg_id' => null, 'group' => null, 'source' => null, 'epg' => null], $over);
}

describe('recognising a country', function () {
    it('reads the playlist id', function () {
        expect(Countries::fromGuideId('CanalShowsport.ar@SD'))->toBe('ar')->and(Countries::fromGuideId('BBCOne.uk'))->toBe('gb')->and(Countries::fromGuideId('Sahara24.mr@HD'))->toBe('mr')
            ->and(Countries::fromGuideId('Thing.zz'))->toBeNull()->and(Countries::fromGuideId('1:0:19:D49:1450:13E:820000:0:0:0:'))->toBeNull()->and(Countries::fromGuideId(null))->toBeNull();
    });

    it('reads a country written as text', function (string $text, ?string $code) {
        expect(Countries::fromText($text))->toBe($code);
    })->with([['Italy', 'it'], ['UK', 'gb'], ['USA', 'us'], ['VOD Italy', 'it'], ['Czech Republic', 'cz'], ['🇯🇵 / Japan', 'jp'], ['North Macedonia', 'mk'], ['Macedonia', 'mk'], ['News', null], ['Radio', null]]);

    it('reads a tag at the start of a name', function (string $name, ?string $code) {
        expect(Countries::fromNameTag($name))->toBe($code);
    })->with([['IT: Rai 1', 'it'], ['|DE| Pro7', 'de'], ['[RS] RTS 1', 'rs'], ['(US) CNN', 'us'], ['MK - Kanal 5', 'mk'], ['BH: Alfa TV', 'ba'], ['UK - BBC', 'gb'], ['TV: Something', null], ['Rai 1', null], ['Ab: x', null]]);

    it('reads words that point at a country', function (string $text, ?string $code) {
        expect(Countries::fromKeywords($text))->toBe($code);
    })->with([['GERMAN FTA SD', 'de'], ['WDR Köln', 'de'], ['Freesat Romania', 'ro'], ['19.2 E - ORF Digital', 'at'], ['T2 36 OIV Hrvatska', 'hr'], ['RTSH 1', 'al'], ['Rai News 24', 'it'], ['Discovery', null], ['Newsmax Polska', 'pl']]);

    it('knows every country the guide catalog offers', function () {
        foreach (array_keys(EpgGuideCatalog::COUNTRIES) as $code) {
            expect(Countries::NAMES)->toHaveKey($code);
        }
    });
});

describe('placing a channel', function () {
    $service = fn () => app(CountryOrganizeService::class);

    it('prefers the playlist id, then the group, the name tag, the list, the guide, then words', function () use ($service) {
        expect($service()->country(orgInfo(['tvg_id' => 'Pink.rs@SD', 'group' => 'Italy', 'name' => 'IT: x'])))->toBe('rs')
            ->and($service()->country(orgInfo(['group' => 'Italy', 'name' => 'DE: x'])))->toBe('it')
            ->and($service()->country(orgInfo(['group' => 'Italy – News'])))->toBe('it')
            ->and($service()->country(orgInfo(['name' => 'DE: Pro7', 'source' => 'ITALIJA'])))->toBe('de')
            ->and($service()->country(orgInfo(['source' => 'ITALIJA'])))->toBe('it')
            ->and($service()->country(orgInfo(['source' => 'Macedonia – TV'])))->toBe('mk')
            ->and($service()->country(orgInfo(['epg' => 'hu', 'group' => 'Genaral'])))->toBe('hu')
            ->and($service()->country(orgInfo(['group' => 'GERMAN FTA SD'])))->toBe('de')
            ->and($service()->country(orgInfo(['name' => 'Something odd'])))->toBeNull();
    });

    it('finds the genre, radio and adult', function () use ($service) {
        expect($service()->genre(orgInfo(['name' => 'Sky Sport 1'])))->toBe('Sports')
            ->and($service()->genre(orgInfo(['name' => 'Rai Movie'])))->toBe('Movies')
            ->and($service()->genre(orgInfo(['name' => 'Whatever', 'source' => 'iptv-org – News'])))->toBe('News')
            ->and($service()->genre(orgInfo(['name' => 'Whatever', 'group' => 'Kids TV'])))->toBe('Kids')
            ->and($service()->genre(orgInfo(['name' => 'Plain'])))->toBe('General')
            ->and($service()->genre(orgInfo(['name' => '(RADIO) Marca'])))->toBe('Radio')
            ->and($service()->genre(orgInfo(['name' => 'Zeta', 'tvg_id' => '1:0:2:1:1:1:C00000:0:0:0:'])))->toBe('Radio')
            ->and($service()->genre(orgInfo(['name' => 'Hot Babes TV'])))->toBe('Adult')
            ->and($service()->genre(orgInfo(['name' => 'The Chat Show'])))->toBe('General');
    });
});

describe('the plan and applying it', function () {
    it('names the categories after countries, with radio, adult and international apart', function () {
        orgChannel('Rai 1', 'General', ['tvg_id' => 'Rai1.it']);
        orgChannel('Pink', 'Music', ['tvg_id' => 'Pink.rs']);
        orgChannel('Radio Zeta', 'Radio');
        orgChannel('Hot Babes TV', 'Erotic');
        orgChannel('Odd one', 'Other');

        $result = app(CountryOrganizeService::class)->apply();
        $names = Channel::with('category')->get()->mapWithKeys(fn ($c) => [$c->name => $c->category->name])->all();

        expect($names)->toBe(['Rai 1' => 'Italy', 'Pink' => 'Serbia', 'Radio Zeta' => 'Radio', 'Hot Babes TV' => 'Adult', 'Odd one' => 'International'])
            ->and($result['changed'])->toBe(4)   // Radio Zeta was in a group called Radio already
            // the old groups are gone, nothing else is left empty
            ->and(Category::pluck('name')->sort()->values()->all())->toBe(['Adult', 'International', 'Italy', 'Radio', 'Serbia']);

        // Repeating changes nothing
        expect(app(CountryOrganizeService::class)->apply())->toBe(['changed' => 0, 'categories' => 0]);
    });

    it('splits a big country by genre, and keeps small genres in the plain country', function () {
        $source = Source::create(['name' => 'iptv-org – News', 'type' => 'url', 'url' => 'http://a.test/a.m3u']);
        foreach (range(1, 6) as $i) {
            orgChannel("News $i", null, ['tvg_id' => "N$i.it", 'source_id' => $source->id]);
        }
        foreach (range(1, 5) as $i) {
            orgChannel("Sport $i", null, ['tvg_id' => "S$i.it"]);
        }
        orgChannel('Cartoon 1', null, ['tvg_id' => 'C1.it']);
        orgChannel('Plain', null, ['tvg_id' => 'P1.it']);
        orgChannel('Small', null, ['tvg_id' => 'S1.rs']);

        app(CountryOrganizeService::class)->apply(null, ['split' => 10, 'min' => 4, 'exclude' => []]);
        $counts = Channel::with('category')->get()->countBy(fn ($c) => $c->category->name)->all();

        // Italy has 13 channels (over 10): News (6) and Sports (5) are big enough, Kids (1) and General stay in "Italy"
        expect($counts)->toBe(['Italy – News' => 6, 'Italy – Sports' => 5, 'Italy' => 2, 'Serbia' => 1]);
    });

    it('decides the split by enabled channels only, but moves every channel', function () {
        foreach (range(1, 20) as $i) {
            orgChannel("Off $i", null, ['tvg_id' => "O$i.it", 'is_active' => false]);
        }
        orgChannel('On', null, ['tvg_id' => 'On.it']);

        $plan = app(CountryOrganizeService::class)->plan(null, ['split' => 10, 'min' => 4]);

        expect($plan['counts'])->toBe(['Italy' => 1])->and($plan['total'])->toBe(1)->and($plan['assign'])->toHaveCount(21);
    });

    it('leaves chosen sources alone', function () {
        $keep = Source::create(['name' => 'Mine', 'type' => 'url', 'url' => 'http://a.test/a.m3u']);
        $kept = orgChannel('Rai 1', 'My own group', ['tvg_id' => 'Rai1.it', 'source_id' => $keep->id]);
        $moved = orgChannel('Rai 2', 'Old', ['tvg_id' => 'Rai2.it']);

        app(CountryOrganizeService::class)->apply(null, ['exclude' => [$keep->id]]);

        expect($kept->fresh()->category->name)->toBe('My own group')->and($moved->fresh()->category->name)->toBe('Italy');
    });

    it('uses the country of the matched TV guide', function () {
        $guide = EpgSource::create(['name' => 'Hungary', 'url' => 'https://iptv-epg.org/files/epg-hu.xml.gz']);
        $channel = orgChannel('Mystery', 'Package 1', ['epg_source_id' => $guide->id]);

        app(CountryOrganizeService::class)->apply();

        expect($channel->fresh()->category->name)->toBe('Hungary');
    });

    it('works on one source only', function () {
        $a = Source::create(['name' => 'A', 'type' => 'url', 'url' => 'http://a.test/a.m3u']);
        $one = orgChannel('Rai 1', 'Old', ['tvg_id' => 'Rai1.it', 'source_id' => $a->id]);
        $two = orgChannel('Rai 2', 'Old', ['tvg_id' => 'Rai2.it']);

        app(CountryOrganizeService::class)->apply($a->id);

        expect($one->fresh()->category->name)->toBe('Italy')->and($two->fresh()->category->name)->toBe('Old');
    });
});

describe('settings and imports', function () {
    it('stores the settings as one rule and reads them back', function () {
        $service = app(CountryOrganizeService::class);
        expect($service->settings())->toBe(['enabled' => false, 'split' => 120, 'min' => 12, 'exclude' => []]);

        $service->save(['split' => 50, 'min' => 5, 'exclude' => ['3', 4]], true);
        expect($service->settings())->toBe(['enabled' => true, 'split' => 50, 'min' => 5, 'exclude' => [3, 4]])->and(CleanupRule::where('kind', 'country')->count())->toBe(1);

        $service->save(['split' => 60], false);
        expect($service->settings())->toBe(['enabled' => false, 'split' => 60, 'min' => 5, 'exclude' => [3, 4]])->and(CleanupRule::where('kind', 'country')->count())->toBe(1);
    });

    it('puts imported channels in their country when it is on, and not when it is off', function () {
        $source = Source::create(['name' => 'Free list', 'type' => 'url', 'url' => 'http://sat.test/a.m3u']);
        $m3u = "#EXTM3U\n#EXTINF:-1 tvg-id=\"Rai1.it\" group-title=\"General\",Rai 1\nhttp://s.test/1\n#EXTINF:-1 tvg-id=\"Pink.rs\" group-title=\"General\",Pink\nhttp://s.test/2\n";
        $import = function () use ($source, $m3u) {
            refakeHttp(['*' => fn () => Http::response($m3u)]);
            app(SourceSyncService::class)->sync($source);
        };

        $import();
        expect(Channel::with('category')->get()->pluck('category.name')->unique()->all())->toBe(['General']);

        app(CountryOrganizeService::class)->save([], true);
        $import();
        expect(Channel::with('category')->get()->pluck('category.name', 'name')->all())->toBe(['Rai 1' => 'Italy', 'Pink' => 'Serbia'])
            ->and(Category::pluck('name')->sort()->values()->all())->toBe(['Italy', 'Serbia']);
    });
});

describe('the page', function () {
    beforeEach(fn () => $this->admin = User::factory()->admin()->create());

    it('previews without changing anything, saves, and applies', function () {
        $this->actingAs($this->admin);
        orgChannel('Rai 1', 'Old', ['tvg_id' => 'Rai1.it']);
        orgChannel('Odd', 'Old');

        $this->get(route('admin.cleanup.index'))->assertOk()->assertSee('Organize by country');

        $this->post(route('admin.cleanup.country'), ['split' => 120, 'min' => 12, 'do' => 'preview'])
            ->assertSessionHas('country_preview', fn ($p) => $p['counts'] === ['International' => 1, 'Italy' => 1] && $p['international'] === ['Odd']);
        expect(Category::pluck('name')->all())->toBe(['Old'])->and(CleanupRule::where('kind', 'country')->exists())->toBeFalse();

        $this->post(route('admin.cleanup.country'), ['split' => 120, 'min' => 12, 'do' => 'save', 'enabled' => 1])->assertSessionHas('success', 'Saved. Country categories are on: every import follows them.');
        expect(app(CountryOrganizeService::class)->settings()['enabled'])->toBeTrue()->and(Category::pluck('name')->all())->toBe(['Old']);

        $this->post(route('admin.cleanup.country'), ['split' => 120, 'min' => 12, 'do' => 'apply'])->assertSessionHas('success', '2 channels moved into country categories.');
        expect(Category::pluck('name')->sort()->values()->all())->toBe(['International', 'Italy']);
    });

    it('is applied by Apply now too when it is on, and is not listed as an ordinary rule', function () {
        $this->actingAs($this->admin);
        orgChannel('Rai 1', 'Old', ['tvg_id' => 'Rai1.it']);
        app(CountryOrganizeService::class)->save([], true);

        $this->post(route('admin.cleanup.apply'))->assertSessionHas('success');
        expect(Channel::first()->category->name)->toBe('Italy');

        $this->get(route('admin.cleanup.index'))->assertOk()->assertDontSee('<code>country</code>', false)->assertSee('No rules yet');
    });

    it('validates and is for admins only', function () {
        $this->actingAs($this->admin)->post(route('admin.cleanup.country'), ['split' => -1, 'min' => 0, 'do' => 'nonsense'])->assertSessionHasErrors(['split', 'min', 'do']);
        $this->actingAs(User::factory()->create(['role' => 'reseller']))->post(route('admin.cleanup.country'), ['split' => 1, 'min' => 1, 'do' => 'save'])->assertForbidden();
    });
});
