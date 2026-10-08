<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\StreamLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What people watch, from the play history (stream_logs): totals, plays per day and per hour of the
 * day, the most played channels and the most active users. A "play" is one stream start (zapping to
 * another channel counts again). History is kept LOG_RETENTION_DAYS (90), so that is the longest period.
 * Every number comes from grouped queries over the started_at index, never from loading rows, and
 * the result is cached for a few minutes.
 */
class StatisticsService
{
    public const PERIODS = [7, 30, 90];

    private const CACHE_SECONDS = 300;

    /**
     * @return array{days: int, from: Carbon, plays: int, viewers: int, channels: int, per_day: array<string, int>, per_hour: array<int, int>, top_channels: list<array{name: string, plays: int, viewers: int}>, top_users: list<array{username: string, plays: int, channels: int}>}
     */
    public function report(int $days): array
    {
        $days = in_array($days, self::PERIODS, true) ? $days : self::PERIODS[1];

        return Cache::remember("statistics:$days", self::CACHE_SECONDS, fn () => $this->build($days));
    }

    private function build(int $days): array
    {
        $from = now()->startOfDay()->subDays($days - 1);
        $logs = fn () => StreamLog::where('started_at', '>=', $from);

        $perDay = array_fill_keys(array_map(fn ($i) => $from->copy()->addDays($i)->toDateString(), range(0, $days - 1)), 0);
        foreach ($logs()->select(DB::raw($this->dayExpression().' as day'), DB::raw('count(*) as plays'))->groupBy('day')->get() as $row) {
            $perDay[(string) $row->day] = (int) $row->plays;
        }

        $perHour = array_fill(0, 24, 0);
        foreach ($logs()->select(DB::raw($this->hourExpression().' as hour'), DB::raw('count(*) as plays'))->groupBy('hour')->get() as $row) {
            $perHour[(int) $row->hour] = (int) $row->plays;
        }

        $topChannels = $logs()->select('channel_id', DB::raw('count(*) as plays'), DB::raw('count(distinct user_id) as viewers'))
            ->groupBy('channel_id')->orderByDesc('plays')->orderBy('channel_id')->limit(10)->get();
        $names = Channel::whereIn('id', $topChannels->pluck('channel_id'))->pluck('name', 'id');

        $topUsers = $logs()->select('user_id', DB::raw('count(*) as plays'), DB::raw('count(distinct channel_id) as channels'))
            ->groupBy('user_id')->orderByDesc('plays')->orderBy('user_id')->limit(10)->get();
        $usernames = User::whereIn('id', $topUsers->pluck('user_id'))->pluck('username', 'id');

        return [
            'days' => $days,
            'from' => $from,
            'plays' => array_sum($perDay),
            'viewers' => $logs()->distinct()->count('user_id'),
            'channels' => $logs()->distinct()->count('channel_id'),
            'per_day' => $perDay,
            'per_hour' => $perHour,
            'top_channels' => $topChannels->map(fn ($row) => ['name' => $names[$row->channel_id] ?? null, 'plays' => (int) $row->plays, 'viewers' => (int) $row->viewers])->all(),
            'top_users' => $topUsers->map(fn ($row) => ['username' => $usernames[$row->user_id] ?? null, 'plays' => (int) $row->plays, 'channels' => (int) $row->channels])->all(),
        ];
    }

    private function dayExpression(): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? 'date(started_at)' : 'DATE(started_at)';
    }

    private function hourExpression(): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? "CAST(strftime('%H', started_at) AS INTEGER)" : 'HOUR(started_at)';
    }
}
