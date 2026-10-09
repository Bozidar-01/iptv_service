<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tidy-up rule, applied when a source is imported and on demand to the channels we have.
 *
 *  - channel_name: change the channel's name (contains: remove / replace the text; equals: rename; regex: preg_replace).
 *  - category: change the playlist's group name (contains / equals: the whole category becomes `replacement`; regex: preg_replace).
 *  - channel_category: a channel whose name matches goes into the category `replacement`.
 *
 * `source_id` limits a rule to one source. A channel_category rule with `when_category` only touches channels that are
 * in that category at that point (after the category rules), e.g. to sort the "Satellite" group by genre. Rules run in `sort_order`, then id.
 */
#[Fillable(['kind', 'match', 'pattern', 'replacement', 'when_category', 'source_id', 'enabled', 'sort_order'])]
class CleanupRule extends Model
{
    public const CHANNEL_NAME = 'channel_name';

    public const CATEGORY = 'category';

    public const CHANNEL_CATEGORY = 'channel_category';

    public const KINDS = [self::CHANNEL_NAME, self::CATEGORY, self::CHANNEL_CATEGORY];

    public const MATCHES = ['contains', 'equals', 'regex'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /** A pattern is usable: a regex has to compile. */
    public static function patternIsValid(string $match, string $pattern): bool
    {
        return $pattern !== '' && ($match !== 'regex' || @preg_match(self::regex($pattern), '') !== false);
    }

    private static function regex(string $pattern): string
    {
        return '~'.str_replace('~', '\~', $pattern).'~iu';
    }

    /** Does the value match the rule's pattern? */
    public function matches(string $value): bool
    {
        return match ($this->match) {
            'equals' => mb_strtolower(trim($value)) === mb_strtolower(trim($this->pattern)),
            'regex' => (bool) @preg_match(self::regex($this->pattern), $value),
            default => mb_stripos($value, $this->pattern) !== false,
        };
    }

    /** A channel name after this rule (never empty: a rule can't wipe a name out). */
    public function rewriteName(string $name): string
    {
        $new = match ($this->match) {
            'equals' => $this->matches($name) ? (string) $this->replacement : $name,
            'regex' => (string) (@preg_replace(self::regex($this->pattern), (string) $this->replacement, $name) ?? $name),
            default => str_ireplace($this->pattern, (string) $this->replacement, $name),
        };
        $new = trim((string) preg_replace('/\s+/u', ' ', $new));

        return $new === '' ? $name : $new;
    }

    /** A category (group) name after this rule. */
    public function rewriteCategory(string $group): string
    {
        if ($this->match === 'regex') {
            $new = trim((string) (@preg_replace(self::regex($this->pattern), (string) $this->replacement, $group) ?? $group));

            return $new === '' ? $group : $new;
        }

        return $this->matches($group) ? (string) $this->replacement : $group;
    }
}
