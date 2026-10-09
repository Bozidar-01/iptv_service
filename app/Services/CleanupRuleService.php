<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Channel;
use App\Models\CleanupRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tidies names and categories with the admin's rules: the importer asks `for($sourceId)` and applies it to every entry
 * (the channel's identity key still comes from the raw playlist, so rules can change without duplicating channels), and
 * `applyToExisting()` applies the same rules to the channels already stored.
 */
class CleanupRuleService
{
    /** Ready-made rule sets the admin can add in one click. */
    public const PACKS = [
        'tags' => ['rules' => [
            [CleanupRule::CHANNEL_NAME, 'regex', '\s*\[[^\]]*\]\s*$', ''],
        ]],
        'radio' => ['rules' => [
            [CleanupRule::CHANNEL_NAME, 'regex', '^\(RADIO\)\s*', ''],
            [CleanupRule::CHANNEL_CATEGORY, 'regex', '^\(RADIO\)|^Radio\b', 'Radio'],
        ]],
        'satellite' => ['rules' => [
            [CleanupRule::CATEGORY, 'regex', '^\(TV\)\s+\d{4,5}\s+[HV]\b.*$', 'Satellite'],
        ]],
        // Only for the "Satellite" group (receiver channels): other sources already come sorted by country or genre
        'genres' => ['when' => 'Satellite', 'rules' => [
            [CleanupRule::CHANNEL_CATEGORY, 'regex', 'sport|eurosport|dazn|bein|arena|\bf1\b|motor|golf|tennis', 'Sports'],
            [CleanupRule::CHANNEL_CATEGORY, 'regex', '\bnews\b|\bnotizie\b|tg\s?\d|cnn|euronews|al\s?jazeera|bbc world|rai news|sky tg', 'News'],
            [CleanupRule::CHANNEL_CATEGORY, 'regex', 'kids|junior|disney|nick|cartoon|boomerang|baby|bambini|pop\b|k2\b|clan', 'Kids'],
            [CleanupRule::CHANNEL_CATEGORY, 'regex', 'cinema|movie|film|premium|hbo|fox|cine|kino|\bcrime\b', 'Movies'],
            [CleanupRule::CHANNEL_CATEGORY, 'regex', 'music|mtv|vh1|hit|folk|radio italia tv|deejay|trace|kiss', 'Music'],
            [CleanupRule::CHANNEL_CATEGORY, 'regex', 'documentar|discovery|history|nat geo|national geographic|animal|science|\bdmax\b', 'Documentary'],
        ]],
    ];

    /** @var array<int, Collection<int, CleanupRule>>|null */
    private ?array $cache = null;

    /**
     * What to apply to one source's entries: global rules and the ones for this source.
     */
    public function for(?int $sourceId): CleanupRules
    {
        return new CleanupRules($this->rulesFor($sourceId));
    }

    /** @return Collection<int, CleanupRule> */
    private function rulesFor(?int $sourceId): Collection
    {
        $all = $this->cache ??= [0 => CleanupRule::where('enabled', true)->orderBy('sort_order')->orderBy('id')->get()];

        return $all[0]->filter(fn (CleanupRule $r) => $r->source_id === null || $r->source_id === $sourceId)->values();
    }

    /**
     * Add the rules of a pack, skipping ones that already exist.
     *
     * @return int rules added
     */
    public function addPack(string $pack): int
    {
        $added = 0;

        foreach (self::PACKS[$pack]['rules'] ?? [] as [$kind, $match, $pattern, $replacement]) {
            $rule = CleanupRule::firstOrCreate(
                ['kind' => $kind, 'match' => $match, 'pattern' => $pattern, 'source_id' => null],
                ['replacement' => $replacement, 'when_category' => self::PACKS[$pack]['when'] ?? null, 'enabled' => true, 'sort_order' => (int) CleanupRule::max('sort_order') + 1],
            );
            $added += $rule->wasRecentlyCreated ? 1 : 0;
        }

        return $added;
    }

    /**
     * How a set of rules would change the channels we have: how many names and categories, and a few examples.
     *
     * @param  Collection<int, CleanupRule>|null  $rules  null = the saved, enabled rules
     * @return array{names: int, categories: int, examples: list<array{0: string, 1: string}>}
     */
    public function preview(?Collection $rules = null, int $examples = 8): array
    {
        $result = ['names' => 0, 'categories' => 0, 'examples' => []];

        $this->eachChange($rules, function (Channel $channel, string $name, ?string $category) use (&$result, $examples) {
            $nameChanged = $name !== $channel->name;
            $categoryChanged = $category !== ($channel->category?->name);
            $result['names'] += $nameChanged ? 1 : 0;
            $result['categories'] += $categoryChanged ? 1 : 0;

            if (($nameChanged || $categoryChanged) && count($result['examples']) < $examples) {
                $result['examples'][] = [
                    $channel->name.($channel->category ? ' ('.$channel->category->name.')' : ''),
                    $name.($category !== null ? ' ('.$category.')' : ''),
                ];
            }
        });

        return $result;
    }

    /**
     * Apply the saved rules to the channels already stored. Set-based: one query per 1,000 changed channels per column;
     * categories that lose all their channels are deleted.
     *
     * @return array{names: int, categories: int}
     */
    public function applyToExisting(): array
    {
        $names = $categories = [];
        $categoryIds = Category::pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [mb_strtolower($name) => $id])->all();
        $leftCategories = [];
        $count = ['names' => 0, 'categories' => 0];

        $flush = function () use (&$names, &$categories, &$count) {
            $this->updateColumn('name', $names);
            $this->updateColumn('category_id', $categories);
            $count['names'] += count($names);
            $count['categories'] += count($categories);
            $names = $categories = [];
        };

        $this->eachChange(null, function (Channel $channel, string $name, ?string $category) use (&$names, &$categories, &$categoryIds, &$leftCategories, $flush) {
            if ($name !== $channel->name) {
                $names[$channel->id] = mb_substr($name, 0, 255);
            }
            if ($category !== ($channel->category?->name)) {
                if ($channel->category_id) {
                    $leftCategories[$channel->category_id] = true;
                }
                $key = $category === null ? null : mb_strtolower($category);
                $categories[$channel->id] = $key === null ? null : ($categoryIds[$key] ??= Category::create(['name' => mb_substr($category, 0, 255)])->id);
            }
            if (count($names) + count($categories) >= 1000) {
                $flush();
            }
        });
        $flush();

        // A category everything was moved out of is no longer needed
        if ($leftCategories !== []) {
            Category::whereIn('id', array_keys($leftCategories))->whereNotExists(fn ($q) => $q->selectRaw('1')->from('channels')->whereColumn('channels.category_id', 'categories.id'))->delete();
        }

        return $count;
    }

    /**
     * Call $each(channel, new name, new category name) for every channel, with the rules of its own source.
     *
     * @param  Collection<int, CleanupRule>|null  $only  use these rules instead of the saved ones
     */
    private function eachChange(?Collection $only, callable $each): void
    {
        $perSource = [];

        Channel::with('category:id,name')->select(['id', 'name', 'source_id', 'category_id'])->chunkById(1000, function ($channels) use ($only, $each, &$perSource) {
            foreach ($channels as $channel) {
                $rules = $perSource[$channel->source_id ?? 0] ??= new CleanupRules(
                    ($only ?? $this->rulesFor($channel->source_id))->filter(fn (CleanupRule $r) => $r->source_id === null || $r->source_id === $channel->source_id)->values()
                );

                $name = $rules->name($channel->name);
                $category = $rules->category($channel->category?->name);
                $category = $rules->channelCategory($name, $category) ?? $category;

                $each($channel, $name, $category);
            }
        });
    }

    /** @param  array<int, int|string|null>  $values  channel id => new value; one query */
    private function updateColumn(string $column, array $values): void
    {
        if ($values === []) {
            return;
        }

        $cases = implode(' ', array_fill(0, count($values), 'WHEN ? THEN ?'));
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $bindings = [];
        foreach ($values as $id => $value) {
            array_push($bindings, $id, $value);
        }

        DB::update("UPDATE channels SET {$column} = CASE id {$cases} END WHERE id IN ({$placeholders})", [...$bindings, ...array_keys($values)]);
    }
}
