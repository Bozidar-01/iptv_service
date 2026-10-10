<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Channel;
use App\Models\CleanupRule;
use App\Models\EpgSource;
use App\Models\Source;
use App\Support\Countries;
use Illuminate\Support\Facades\DB;

/**
 * Puts every channel in a category named after its country ("Italy", "Serbia"), so apps show a tidy list.
 *
 * Country comes from, in this order: the playlist id (`Name.de@SD`), the group name when it is a country ("Italy", "UK"),
 * a tag in the name (`IT:`, `|DE|`), the source's own name (a list called ITALIJA), the country of the TV guide the channel is matched
 * to, and words in the group or name (ORF, WDR, Freesat Romania). The rest goes to "International". Radio is one "Radio" category.
 * A country with many channels (over `split`) is split by genre ("Italy – News", "Italy – Sports"…): genres with fewer than `min`
 * channels stay in the plain "Italy". The settings are one `cleanup_rules` row (kind `country`, JSON in `replacement`).
 */
class CountryOrganizeService
{
    public const KIND = 'country';

    public const DEFAULTS = ['split' => 120, 'min' => 12, 'exclude' => []];

    public const INTERNATIONAL = 'International';

    public const RADIO = 'Radio';

    /** Adult channels get their own category, so it can be hidden in one click (Hide channels → By category). */
    public const ADULT = 'Adult';

    private const ADULT_WORDS = '/\berotic|\badult|\bxxx\b|\bbabes?\b|\bsex|\bporn|\bplayboy\b|\bpenthouse\b|\bdorcel\b|\bhustler\b|\bbrazzers\b/iu';

    /** Genre => words that show it, looked at in a group name first and then in the channel name. */
    private const GENRES = [
        'Sports' => '/\bsport|\beurosport|\bdazn\b|\bbein\b|\bf1\b|\bmotor|\bgolf\b|\btennis\b|\bfootball\b|\bfutbol|\bnba\b/iu',
        'News' => '/\bnews\b|\bnotizie\b|\btg ?\d|\bcnn\b|\beuronews\b|\bjazeera\b|\bvijesti\b|\bnachrichten\b/iu',
        'Kids' => '/\bkids?\b|\bjunior\b|\bdisney\b|\bnick(elodeon)?\b|\bcartoon|\bboomerang\b|\bbaby\b|\banimation\b|\bchildren\b|\bdjecji\b/iu',
        'Movies' => '/\bmovies?\b|\bcinema\b|\bfilm\b|\bkino\b|\bseries\b|\bserije\b|\bhbo\b|\bpremium\b/iu',
        'Music' => '/\bmusic\b|\bmuzika\b|\bmtv\b|\bvh1\b|\bhits?\b|\bfolk\b|\btrace\b|\bmelody\b/iu',
        'Documentary' => '/\bdocumentar|\bdiscovery\b|\bhistory\b|\bnat(ional)? geo|\banimal planet\b|\bscience\b/iu',
    ];

    /** @return array{enabled: bool, split: int, min: int, exclude: list<int>} */
    public function settings(): array
    {
        $rule = CleanupRule::where('kind', self::KIND)->first();
        $json = $rule ? (json_decode((string) $rule->replacement, true) ?: []) : [];

        return [
            'enabled' => (bool) $rule?->enabled,
            'split' => (int) ($json['split'] ?? self::DEFAULTS['split']),
            'min' => (int) ($json['min'] ?? self::DEFAULTS['min']),
            'exclude' => array_map('intval', (array) ($json['exclude'] ?? [])),
        ];
    }

    /** @param  array{split?: int, min?: int, exclude?: list<int>}  $values */
    public function save(array $values, bool $enabled): void
    {
        $current = $this->settings();
        $json = json_encode([
            'split' => max(0, (int) ($values['split'] ?? $current['split'])),
            'min' => max(1, (int) ($values['min'] ?? $current['min'])),
            'exclude' => array_values(array_map('intval', $values['exclude'] ?? $current['exclude'])),
        ]);

        CleanupRule::updateOrCreate(['kind' => self::KIND], ['match' => 'contains', 'pattern' => 'country', 'replacement' => $json, 'enabled' => $enabled, 'sort_order' => 9999]);
    }

    /**
     * The country code of a channel, or null.
     *
     * @param  array{name: string, tvg_id: ?string, group: ?string, source: ?string, epg: ?string}  $c
     */
    public function country(array $c): ?string
    {
        // The group name may already be one of ours: "Italy – News"
        $group = trim(explode(' – ', (string) $c['group'])[0]);

        return Countries::fromGuideId($c['tvg_id'])
            ?? ($group !== '' ? Countries::fromText($group) : null)
            ?? Countries::fromNameTag($c['name'])
            ?? ($c['source'] ? Countries::fromText($c['source']) ?? Countries::fromKeywords($c['source']) : null)
            ?? $c['epg']
            ?? ($group !== '' ? Countries::fromKeywords($group) : null)
            ?? Countries::fromKeywords($c['name']);
    }

    /** @param  array{name: string, tvg_id: ?string, group: ?string, source: ?string, epg: ?string}  $c */
    public function genre(array $c): string
    {
        $group = (string) $c['group'];
        if (preg_match(self::ADULT_WORDS, $group) || preg_match(self::ADULT_WORDS, $c['name'])) {
            return self::ADULT;
        }
        $ref = explode(':', (string) $c['tvg_id']);

        if (preg_match('/^\(?radio\b/i', $c['name']) || preg_match('/\bradio\b/i', $group) || (isset($ref[2], $ref[3]) && $ref[0] === '1' && in_array(strtoupper($ref[2]), ['2', 'A'], true))) {
            return self::RADIO;
        }
        if ($c['source'] && preg_match('/iptv-org\W+(\w+)/iu', $c['source'], $m)) {
            foreach (self::GENRES as $genre => $pattern) {
                if (preg_match($pattern, $m[1])) {
                    return $genre;
                }
            }
        }
        foreach ([$group, $c['name']] as $text) {
            foreach (self::GENRES as $genre => $pattern) {
                if (preg_match($pattern, $text)) {
                    return $genre;
                }
            }
        }

        return 'General';
    }

    /**
     * The new category name of every channel (channel id => name), the number of channels per name and a few examples that
     * ended up in "International".
     *
     * Counts and examples are for enabled channels (what users see); `assign` covers every channel.
     *
     * @param  array{split?: int, min?: int, exclude?: list<int>}|null  $override  settings to try instead of the saved ones (preview)
     * @return array{assign: array<int, string>, counts: array<string, int>, international: list<string>, total: int}
     */
    public function plan(?int $onlySource = null, ?array $override = null): array
    {
        $settings = $override ? array_merge($this->settings(), $override) : $this->settings();
        $groups = Category::pluck('name', 'id')->all();
        $sources = Source::pluck('name', 'id')->all();
        $epg = EpgSource::get(['id', 'url'])->mapWithKeys(fn (EpgSource $s) => [$s->id => $s->country()])->all();

        $rows = [];
        $tally = [];
        Channel::query()->select(['id', 'name', 'tvg_id', 'category_id', 'source_id', 'epg_source_id', 'is_active'])
            ->when($settings['exclude'], fn ($q) => $q->where(fn ($q) => $q->whereNull('source_id')->orWhereNotIn('source_id', $settings['exclude'])))
            ->chunkById(5000, function ($channels) use (&$rows, &$tally, $groups, $sources, $epg) {
                foreach ($channels as $channel) {
                    $info = ['name' => $channel->name, 'tvg_id' => $channel->tvg_id, 'group' => $groups[$channel->category_id] ?? null, 'source' => $sources[$channel->source_id] ?? null, 'epg' => $epg[$channel->epg_source_id] ?? null];
                    $genre = $this->genre($info);
                    $global = in_array($genre, [self::RADIO, self::ADULT], true);
                    $country = $global ? null : $this->country($info);
                    $rows[$channel->id] = [$channel->source_id, $country, $genre, $channel->name, (bool) $channel->is_active];
                    // The split is decided by the enabled channels: those are what users see
                    if (!$global && $channel->is_active) {
                        $tally[$country ?? ''][$genre] = ($tally[$country ?? ''][$genre] ?? 0) + 1;
                    }
                }
            });

        // Which genres get their own category in which country
        $split = [];
        foreach ($tally as $country => $genres) {
            if ($settings['split'] > 0 && array_sum($genres) > $settings['split']) {
                $split[$country] = array_keys(array_filter($genres, fn ($n, $g) => $g !== 'General' && $n >= $settings['min'], ARRAY_FILTER_USE_BOTH));
            }
        }

        $assign = $counts = $international = [];
        foreach ($rows as $id => [$sourceId, $country, $genre, $name, $active]) {
            if ($onlySource !== null && $sourceId !== $onlySource) {
                continue;
            }
            $label = match (true) {
                $genre === self::RADIO => self::RADIO,
                $genre === self::ADULT => self::ADULT,
                $country === null => self::INTERNATIONAL,
                in_array($genre, $split[$country] ?? [], true) => Countries::name($country).' – '.$genre,
                default => Countries::name($country),
            };
            $assign[$id] = $label;
            if (!$active) {
                continue;
            }
            $counts[$label] = ($counts[$label] ?? 0) + 1;
            if ($label === self::INTERNATIONAL && count($international) < 15) {
                $international[] = $name;
            }
        }
        ksort($counts);

        return ['assign' => $assign, 'counts' => $counts, 'international' => $international, 'total' => array_sum($counts)];
    }

    /**
     * Move the channels into their country categories (one UPDATE per 1,000 changed channels), then delete categories that ended
     * up empty. Safe to repeat.
     *
     * @return array{changed: int, categories: int}
     */
    public function apply(?int $onlySource = null, ?array $override = null): array
    {
        $plan = $this->plan($onlySource, $override);
        $ids = Category::pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [mb_strtolower($name) => $id])->all();
        $current = Channel::whereIn('id', array_keys($plan['assign']))->pluck('category_id', 'id');

        $changes = [];
        foreach ($plan['assign'] as $channelId => $label) {
            $categoryId = $ids[mb_strtolower($label)] ??= Category::create(['name' => $label])->id;
            if ($current[$channelId] !== $categoryId) {
                $changes[$channelId] = $categoryId;
            }
        }

        foreach (array_chunk($changes, 1000, true) as $chunk) {
            $cases = implode(' ', array_fill(0, count($chunk), 'WHEN ? THEN ?'));
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $bindings = [];
            foreach ($chunk as $channelId => $categoryId) {
                array_push($bindings, $channelId, $categoryId);
            }
            DB::update("UPDATE channels SET category_id = CASE id {$cases} END WHERE id IN ({$marks})", [...$bindings, ...array_keys($chunk)]);
        }

        $removed = Category::whereNotExists(fn ($q) => $q->selectRaw('1')->from('channels')->whereColumn('channels.category_id', 'categories.id'))->delete();

        return ['changed' => count($changes), 'categories' => $removed];
    }
}
