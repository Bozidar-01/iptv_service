<?php

use App\Models\AuditLog;
use App\Models\Bouquet;
use App\Models\Channel;
use App\Models\Source;
use App\Models\User;
use App\Services\ChannelDuplicateService;
use App\Services\StreamCheckService;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->bouquet = Bouquet::create(['name' => 'Main']);
    $this->other = Bouquet::create(['name' => 'Other']);
    $this->iptv = Source::create(['name' => 'Provider A', 'type' => 'url', 'url' => 'http://a.test/a.m3u']);
    $this->iptv2 = Source::create(['name' => 'Provider B', 'type' => 'url', 'url' => 'http://b.test/b.m3u']);
    $this->sat = Source::create(['name' => 'Octagon', 'type' => 'receiver', 'url' => 'http://sat.test/']);
});

function dup(string $name, ?Source $source = null, array $attrs = [], Bouquet|false|null $bouquet = null): Channel
{
    $channel = Channel::create(array_merge(['name' => $name, 'stream_url' => 'http://up.test/'.md5($name.microtime()), 'is_active' => true, 'source_id' => $source?->id], $attrs));
    if ($bouquet !== false) {
        ($bouquet ?? test()->bouquet)->channels()->attach($channel);
    }

    return $channel;
}

describe('what counts as the same channel', function () {
    it('ignores case, country prefixes, quality tags and punctuation', function (string $a, string $b) {
        expect(ChannelDuplicateService::normalize($a))->toBe(ChannelDuplicateService::normalize($b));
    })->with([
        ['IT: Rai 1 HD', 'Rai 1'],
        ['|IT| RAI 1 FHD', 'rai 1'],
        ['[DE] Sky Sport 1 (1080p)', 'Sky Sport 1'],
        ['(RADIO) 5 Andalucia (2523)', 'Radio 5 Andalucia [2523]'],
        ['BBC One H.265', 'BBC  One'],
        ['Discovery Channel 4K', 'Discovery Channel'],
    ]);

    it('keeps channels apart that are not the same', function (string $a, string $b) {
        expect(ChannelDuplicateService::normalize($a))->not->toBe(ChannelDuplicateService::normalize($b));
    })->with([
        ['Sky Sports 1', 'Sky Sports +1'],
        ['ITV', 'ITV2'],
        ['CNN - USA', 'USA'],
        ['Rai 1', 'Rai 2'],
    ]);

    it('reads the quality from the name', function (string $name, int $quality) {
        expect(ChannelDuplicateService::quality($name))->toBe($quality);
    })->with([['Movies 4K', 5], ['Movies UHD', 5], ['Movies FHD', 4], ['Movies (1080p)', 4], ['Movies HD', 3], ['Movies 720p', 3], ['Movies', 2], ['Movies SD', 1], ['Movies 576i', 1]]);
});

describe('the groups', function () {
    it('finds copies across and within sources, and leaves single channels alone', function () {
        $a = dup('Rai 1 HD', $this->iptv);
        $b = dup('IT: Rai 1', $this->iptv2);
        $c = dup('Rai 1', $this->iptv2);
        dup('Rai 2', $this->iptv);

        $groups = app(ChannelDuplicateService::class)->groups();

        expect($groups)->toHaveCount(1)
            ->and(collect($groups[0]['channels'])->pluck('id')->sort()->values()->all())->toBe([$a->id, $b->id, $c->id]);
    });

    it('joins copies by guide id, but not by a meaningless one', function () {
        dup('Canale 5', $this->iptv, ['tvg_id' => 'Canale5.it']);
        dup('Canale Cinque', $this->iptv2, ['tvg_id' => 'canale5.it']);
        dup('One', $this->iptv, ['tvg_id' => 'none']);
        dup('Two', $this->iptv2, ['tvg_id' => 'None']);
        dup('Three', $this->iptv2, ['tvg_id' => '']);
        dup('Four', $this->iptv2, ['tvg_id' => '']);

        $groups = app(ChannelDuplicateService::class)->groups();

        expect($groups)->toHaveCount(1)->and(collect($groups[0]['channels'])->pluck('name')->sort()->values()->all())->toBe(['Canale 5', 'Canale Cinque']);
    });

    it('ignores disabled channels and groups nobody gets', function () {
        dup('Alpha', $this->iptv);
        dup('Alpha HD', $this->iptv2, ['is_active' => false]);
        dup('Beta', $this->iptv, [], false);
        dup('Beta HD', $this->iptv2, [], false);

        expect(app(ChannelDuplicateService::class)->groups())->toBe([]);
    });

    it('skips a group that is too big to be one channel', function () {
        foreach (range(1, ChannelDuplicateService::MAX_GROUP_SIZE + 1) as $i) {
            dup('Same name', $this->iptv);
        }

        expect(app(ChannelDuplicateService::class)->groups())->toBe([]);
    });

    it('filters by search', function () {
        dup('Rai 1', $this->iptv);
        dup('Rai 1 HD', $this->iptv2);
        dup('Mega', $this->iptv);
        dup('Mega HD', $this->iptv2);

        expect(app(ChannelDuplicateService::class)->groups('meg'))->toHaveCount(1)->and(app(ChannelDuplicateService::class)->groups())->toHaveCount(2);
    });
});

describe('the suggested copy', function () {
    $suggested = fn () => app(ChannelDuplicateService::class)->groups()[0]['suggested'];

    it('is the one that works, whatever its quality', function () use ($suggested) {
        dup('News 4K', $this->iptv, ['stream_status' => StreamCheckService::STATUS_OFFLINE]);
        $sd = dup('News SD', $this->iptv2, ['stream_status' => StreamCheckService::STATUS_ONLINE]);
        dup('News HD', $this->iptv2);

        expect($suggested())->toBe($sd->id);
    });

    it('is the best quality among those that work', function () use ($suggested) {
        dup('News', $this->iptv, ['stream_status' => 'online']);
        $fhd = dup('News FHD', $this->iptv2, ['stream_status' => 'online']);
        dup('News HD', $this->iptv2, ['stream_status' => 'online']);

        expect($suggested())->toBe($fhd->id);
    });

    it('prefers a checked working copy to one never checked', function () use ($suggested) {
        dup('Sport HD', $this->iptv);
        $checked = dup('Sport HD', $this->iptv2, ['stream_status' => 'online']);

        expect($suggested())->toBe($checked->id);
    });

    it('lets a satellite receiver lose a tie, but win on quality', function () use ($suggested) {
        $iptv = dup('Film HD', $this->iptv, ['stream_status' => 'online']);
        dup('Film HD', $this->sat, ['stream_status' => 'online']);
        expect($suggested())->toBe($iptv->id);

        $sat = dup('Film 4K', $this->sat, ['stream_status' => 'online']);
        expect($suggested())->toBe($sat->id);
    });

    it('goes to the copy in the most bouquets when all else is equal', function () use ($suggested) {
        dup('Kids', $this->iptv);
        $popular = dup('Kids', $this->iptv2);
        $this->other->channels()->attach($popular);

        expect($suggested())->toBe($popular->id);
    });
});

describe('applying', function () {
    it('switches off the others, keeps the chosen one, and puts it in their bouquets', function () {
        $keep = dup('Rai 1 HD', $this->iptv, ['stream_status' => 'online']);
        $lose = dup('Rai 1', $this->iptv2, [], $this->other);
        $key = app(ChannelDuplicateService::class)->groups()[0]['key'];

        $this->actingAs($this->admin)->post(route('admin.channels.duplicates.apply'), ['scope' => 'page', 'keep' => [$key => $keep->id]])
            ->assertRedirect()->assertSessionHas('success', '1 channel switched off. The best copy of each group stays.');

        expect($keep->fresh()->is_active)->toBeTrue()->and($lose->fresh()->is_active)->toBeFalse()
            ->and($keep->bouquets()->pluck('bouquets.id')->sort()->values()->all())->toBe([$this->bouquet->id, $this->other->id]);

        // The disabled copy stays in its own bouquet; nothing is deleted
        expect(Channel::count())->toBe(2)->and(AuditLog::latest('id')->first()->action)->toBe(AuditLog::CHANNELS_DEDUPLICATED);
    });

    it('gives users the kept channel instead of the one switched off', function () {
        $user = User::factory()->create();
        $user->bouquets()->attach($this->other);
        $keep = dup('Rai 1 HD', $this->iptv, ['stream_status' => 'online']);
        $lose = dup('Rai 1', $this->iptv2, [], $this->other);
        expect(Channel::availableTo($user)->pluck('id')->all())->toBe([$lose->id]);

        app(ChannelDuplicateService::class)->apply([app(ChannelDuplicateService::class)->groups()[0]['key'] => $keep->id]);

        expect(Channel::availableTo($user)->pluck('id')->all())->toBe([$keep->id]);
    });

    it('can keep a copy other than the suggested one, and leave a group alone', function () {
        $suggested = dup('Alpha HD', $this->iptv, ['stream_status' => 'online']);
        $chosen = dup('Alpha', $this->iptv2);
        dup('Beta HD', $this->iptv, ['stream_status' => 'online']);
        dup('Beta', $this->iptv2);
        $groups = collect(app(ChannelDuplicateService::class)->groups())->keyBy('name');

        $this->actingAs($this->admin)->post(route('admin.channels.duplicates.apply'), ['scope' => 'page', 'keep' => [
            $groups['Alpha HD']['key'] => $chosen->id,
            $groups['Beta HD']['key'] => 0,
        ]])->assertRedirect();

        expect($chosen->fresh()->is_active)->toBeTrue()->and($suggested->fresh()->is_active)->toBeFalse()
            ->and(Channel::where('is_active', false)->count())->toBe(1);
    });

    it('applies the suggestion to every group, limited by the search', function () {
        foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
            dup($name.' HD', $this->iptv, ['stream_status' => 'online']);
            dup($name, $this->iptv2);
        }

        $this->actingAs($this->admin)->post(route('admin.channels.duplicates.apply'), ['scope' => 'all', 'search' => 'amm'])->assertRedirect();
        expect(Channel::where('is_active', false)->pluck('name')->all())->toBe(['Gamma']);

        $this->actingAs($this->admin)->post(route('admin.channels.duplicates.apply'), ['scope' => 'all'])->assertRedirect();
        expect(Channel::where('is_active', false)->count())->toBe(3)->and(app(ChannelDuplicateService::class)->groups())->toBe([]);
    });

    it('skips a group that changed and a channel that is not in its group', function () {
        dup('Rai 1 HD', $this->iptv);
        dup('Rai 1', $this->iptv2);
        $stranger = dup('Unrelated', $this->iptv);
        $key = app(ChannelDuplicateService::class)->groups()[0]['key'];

        $this->actingAs($this->admin)->post(route('admin.channels.duplicates.apply'), ['scope' => 'page', 'keep' => [$key => $stranger->id, 'made-up' => 1]])
            ->assertSessionHas('success', fn ($m) => str_contains($m, '2 groups changed in the meantime and were skipped.'));

        expect(Channel::where('is_active', false)->count())->toBe(0);
    });
});

describe('the page', function () {
    it('shows the groups with the suggested copy, and is for admins only', function () {
        dup('Rai 1 HD', $this->iptv, ['stream_status' => 'online']);
        dup('Rai 1', $this->sat);

        $this->actingAs($this->admin)->get(route('admin.channels.duplicates'))
            ->assertOk()->assertSee('Duplicate channels')->assertSee('Suggested')->assertSee('Octagon')->assertSee('Receiver')
            ->assertSee('Keep the suggested copy in all 1 groups');

        $this->actingAs($this->admin)->get(route('admin.channels.duplicates', ['search' => 'zzz']))->assertSee('No duplicates match this search');

        $this->actingAs(User::factory()->create(['role' => 'reseller']))->get(route('admin.channels.duplicates'))->assertForbidden();
        auth()->logout();
        $this->get(route('admin.channels.duplicates'))->assertRedirect(route('login'));
    });

    it('is linked from the channels page', function () {
        $this->actingAs($this->admin)->get(route('admin.channels.index'))->assertSee(route('admin.channels.duplicates'), false);
    });
});
