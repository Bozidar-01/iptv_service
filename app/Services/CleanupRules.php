<?php

namespace App\Services;

use App\Models\CleanupRule;
use Illuminate\Support\Collection;

/**
 * The rules that apply to one source, ready to run on entries (built by CleanupRuleService::for()).
 */
class CleanupRules
{
    /** @var Collection<int, CleanupRule> */
    private Collection $names;

    /** @var Collection<int, CleanupRule> */
    private Collection $categories;

    /** @var Collection<int, CleanupRule> */
    private Collection $channelCategories;

    /** @param  Collection<int, CleanupRule>  $rules */
    public function __construct(Collection $rules)
    {
        $this->names = $rules->where('kind', CleanupRule::CHANNEL_NAME)->values();
        $this->categories = $rules->where('kind', CleanupRule::CATEGORY)->values();
        $this->channelCategories = $rules->where('kind', CleanupRule::CHANNEL_CATEGORY)->values();
    }

    public function name(string $name): string
    {
        foreach ($this->names as $rule) {
            $name = $rule->rewriteName($name);
        }

        return $name;
    }

    public function category(?string $group): ?string
    {
        if ($group === null || trim($group) === '') {
            return $group;
        }

        foreach ($this->categories as $rule) {
            $group = $rule->rewriteCategory($group);
        }

        return $group;
    }

    /**
     * The category a channel goes in because of its (cleaned) name, or null when no rule matches. $current is the category it
     * has now, for rules that only apply to one category.
     */
    public function channelCategory(string $name, ?string $current = null): ?string
    {
        return $this->channelCategories
            ->first(fn (CleanupRule $r) => ($r->when_category === null || ($current !== null && mb_strtolower(trim($current)) === mb_strtolower(trim($r->when_category)))) && $r->matches($name))
            ?->replacement;
    }

    public function isEmpty(): bool
    {
        return $this->names->isEmpty() && $this->categories->isEmpty() && $this->channelCategories->isEmpty();
    }
}
