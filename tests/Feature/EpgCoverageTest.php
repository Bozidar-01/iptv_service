<?php

use App\Jobs\SyncEpgSource;
use App\Models\Channel;
use App\Models\EpgChannel;
use App\Models\EpgProgramme;
use App\Models\EpgSource;
use App\Models\User;
use App\Services\EpgGuideCatalog;
use App\Services\EpgImportService;
use App\Services\EpgService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/** A guide channel with one programme now. */
function guideChannel(string $id, string|array $names, string $title = 'Show'): string
{
    $at = fn ($h) => now()->addHours($h)->utc()->format('YmdHis').' +0000';
    $displayNames = collect((array) $names)->map(fn ($n) => '<display-name>'.e($n).'</display-name>')->join('');

    return '<channel id="'.$id.'">'.$displayNames.'</channel>'."\n".'<programme start="'.$at(-1).'" stop="'.$at(1).'" channel="'.$id.'"><title>'.$title.'</title></programme>';
}

/** Create and import guides from XML bodies, keyed by country code (the file is epg-<code>.xml). */
function importGuides(array $guides): array
{
    $stubs = [];
    foreach ($guides as $code => $body) {
        $stubs['guide.test/epg-'.$code.'.xml'] = fn () => Http::response(xmltv($body));
    }
    refakeHttp($stubs);

    $sources = [];
    foreach (array_keys($guides) as $code) {
        $sources[$code] = EpgSource::create(['name' => strtoupper($code), 'url' => 'http://guide.test/epg-'.$code.'.xml']);
        app(EpgImportService::class)->sync($sources[$code]);
    }

    return $sources;
}

function epgChannel(string $name, array $attrs = []): Channel
{
    return Channel::create(array_merge(['name' => $name, 'stream_url' => 'http://x.test/'.md5($name.microtime()), 'is_active' => true], $attrs));
}

describe('what the importer keeps', function () {
    it('keeps the programmes of every guide channel while there are no channels yet', function () {
        importGuides(['it' => guideChannel('Rai1.it', 'Rai 1')."\n".guideChannel('Other.it', 'Other')]);

        expect(EpgProgramme::count())->toBe(2)->and(EpgChannel::count())->toBe(2);
    });

    it('keeps only the programmes of guide channels some channel of ours can match', function () {
        epgChannel('Whatever', ['tvg_id' => 'Rai1.it@HD']);
        epgChannel('Kanal 5');
        epgChannel('Manual one', ['epg_manual' => true, 'epg_id' => 'Custom.id']);
        epgChannel('Disabled', ['tvg_id' => 'Off.it', 'is_active' => false]);

        importGuides(['it' => implode("\n", [
            guideChannel('Rai1.it', 'Rai 1'),           // by id (our id has an @HD suffix)
            guideChannel('K5.mk', 'MK - Kanal 5'),      // by name
            guideChannel('Custom.id', 'Anything'),      // by the admin's own guide id
            guideChannel('Nobody.it', 'Nobody has it'), // not ours
            guideChannel('Off.it', 'Off'),              // only a disabled channel has it
        ])]);

        expect(EpgProgramme::pluck('xmltv_id')->sort()->values()->all())->toBe(['Custom.id', 'K5.mk', 'Rai1.it'])
            // the guide's channel list stays complete, for matching later
            ->and(EpgChannel::count())->toBe(5);
    });

    it('also keeps a guide channel that one of its other names matches', function () {
        epgChannel('Das Erste');

        importGuides(['de' => guideChannel('ARD.de', ['ARD', 'Das Erste HD'])]);

        expect(EpgProgramme::pluck('xmltv_id')->all())->toBe(['ARD.de']);
    });
});

describe('matching', function () {
    it('matches a channel by any display name of the guide channel', function () {
        $guides = importGuides(['de' => guideChannel('ARD.de', ['ARD', 'Das Erste'])]);
        $byAlt = epgChannel('DE: Das Erste HD');
        $byFirst = epgChannel('ARD');

        app(EpgService::class)->matchChannels();

        expect($byAlt->fresh())->epg_id->toBe('ARD.de')->epg_source_id->toBe($guides['de']->id)
            ->and($byFirst->fresh()->epg_id)->toBe('ARD.de');
    });

    it('prefers the guide of the channel\'s own country over the guide added first', function () {
        $guides = importGuides([
            'es' => guideChannel('Sport1.es', 'Sport 1'),
            'de' => guideChannel('Sport1.de', 'Sport 1'),
            'gb' => guideChannel('Sport1.uk', 'Sport 1'),
        ]);
        // a name that is in all three guides, so only the country can decide
        $german = epgChannel('Sport 1', ['tvg_id' => 'SomeOtherId.de']);
        $british = epgChannel('Sport 1', ['tvg_id' => 'SomeOtherId.uk@SD']);
        $unknown = epgChannel('Sport 1');
        $noGuide = epgChannel('Sport 1', ['tvg_id' => 'SomeOtherId.fr']);

        app(EpgService::class)->matchChannels();

        expect($german->fresh()->epg_source_id)->toBe($guides['de']->id)
            ->and($british->fresh()->epg_source_id)->toBe($guides['gb']->id)
            // no country, or a country without a guide: the guide added first
            ->and($unknown->fresh()->epg_source_id)->toBe($guides['es']->id)
            ->and($noGuide->fresh()->epg_source_id)->toBe($guides['es']->id);
    });

    it('reads the country from a playlist id and from a guide file name', function () {
        expect(EpgService::countryOf('CanalShowsport.ar@SD'))->toBe('ar')
            ->and(EpgService::countryOf('BBCOne.uk'))->toBe('gb')
            ->and(EpgService::countryOf('Rai1.IT@HD'))->toBe('it')
            ->and(EpgService::countryOf('1:0:19:D49:1450:13E:820000:0:0:0:'))->toBeNull()
            ->and(EpgService::countryOf('NoDot'))->toBeNull()
            ->and(EpgService::countryOf(null))->toBeNull();

        expect((new EpgSource(['url' => 'https://iptv-epg.org/files/epg-de.xml.gz']))->country())->toBe('de')
            ->and((new EpgSource(['url' => 'http://guide.test/epg-es.xml']))->country())->toBe('es')
            ->and((new EpgSource(['url' => 'https://example.com/guide.xml']))->country())->toBeNull();
    });
});

describe('guides for the countries of the channels', function () {
    it('suggests the countries with channels that have no guide, most first', function () {
        foreach (range(1, 5) as $i) {
            epgChannel('Arg '.$i, ['tvg_id' => 'Arg'.$i.'.ar@SD']);
        }
        foreach (range(1, 3) as $i) {
            epgChannel('Brit '.$i, ['tvg_id' => 'Brit'.$i.'.uk']);
        }
        epgChannel('Few 1', ['tvg_id' => 'Few1.cz']);       // under the minimum
        epgChannel('Odd 1', ['tvg_id' => 'Odd1.zz']);       // no guide file for it
        epgChannel('Off 1', ['tvg_id' => 'Off1.de', 'is_active' => false]);

        expect(array_column(app(EpgGuideCatalog::class)->suggestions(), 'channels', 'code'))->toBe(['ar' => 5, 'gb' => 3]);

        // Having a guide for a country takes it off the list
        EpgSource::create(['name' => 'Argentina', 'url' => EpgGuideCatalog::url('ar')]);

        expect(array_column(app(EpgGuideCatalog::class)->suggestions(), 'code'))->toBe(['gb']);
    });

    it('adds the guides and starts importing them, skipping known ones and unknown countries', function () {
        Queue::fake();
        EpgSource::create(['name' => 'Italy', 'url' => EpgGuideCatalog::url('it')]);

        expect(app(EpgGuideCatalog::class)->add(['de', 'it', 'zz', 'de']))->toBe(1);

        $guide = EpgSource::where('url', EpgGuideCatalog::url('de'))->first();
        expect($guide->name)->toBe('Germany')->and($guide->status)->toBe('queued');
        Queue::assertPushed(SyncEpgSource::class, 1);
    });

    it('is on the TV guide page, for all suggestions or one country', function () {
        Queue::fake();
        $this->actingAs(User::factory()->admin()->create());
        foreach (range(1, 4) as $i) {
            epgChannel('Arg '.$i, ['tvg_id' => 'Arg'.$i.'.ar']);
            epgChannel('Fr '.$i, ['tvg_id' => 'Fr'.$i.'.fr']);
        }

        $this->get(route('admin.epg.index'))->assertOk()->assertSee('Guides your channels could use')->assertSee('Argentina')->assertSee('France')->assertSee('4 channels without a guide');

        $this->post(route('admin.epg.countries'), ['countries' => ['ar']])->assertSessionHas('success', '1 guide added. It is being imported in the background.');
        $this->post(route('admin.epg.countries'), ['all' => 1])->assertSessionHas('success', '1 guide added. It is being imported in the background.');
        $this->post(route('admin.epg.countries'), ['all' => 1])->assertSessionHas('error', 'Nothing to add.');

        expect(EpgSource::count())->toBe(2);
        $this->post(route('admin.epg.countries'), ['countries' => ['toolong']])->assertSessionHasErrors('countries.0');
    });

    it('is for admins only', function () {
        $this->actingAs(User::factory()->create(['role' => 'reseller']))->post(route('admin.epg.countries'), ['all' => 1])->assertForbidden();
    });
});

describe('importing again when new channels need a guide', function () {
    it('queues a guide whose matched channels have no programmes, once in six hours', function () {
        Queue::fake();
        $guides = importGuides(['it' => guideChannel('Rai1.it', 'Rai 1')]);
        // a channel that matches a guide channel whose programmes were dropped
        EpgChannel::create(['epg_source_id' => $guides['it']->id, 'xmltv_id' => 'Rai2.it', 'name' => 'Rai 2', 'name_key' => 'rai2']);
        epgChannel('Rai 2');
        app(EpgService::class)->matchChannels();
        $guides['it']->update(['last_synced_at' => now()->subHours(7)]);

        expect(app(EpgService::class)->refreshGuidesMissingProgrammes())->toBe(1);
        Queue::assertPushed(SyncEpgSource::class, 1);
        expect($guides['it']->fresh()->status)->toBe('queued');

        // Busy now, and just imported: nothing more
        expect(app(EpgService::class)->refreshGuidesMissingProgrammes())->toBe(0);
        $guides['it']->update(['status' => 'success', 'last_synced_at' => now()->subHour()]);
        expect(app(EpgService::class)->refreshGuidesMissingProgrammes())->toBe(0);
    });

    it('leaves guides alone when every matched channel has programmes', function () {
        Queue::fake();
        $guides = importGuides(['it' => guideChannel('Rai1.it', 'Rai 1')]);
        epgChannel('Rai 1');
        app(EpgService::class)->matchChannels();
        $guides['it']->update(['last_synced_at' => now()->subDay()]);

        expect(app(EpgService::class)->refreshGuidesMissingProgrammes())->toBe(0);
    });
});
