<?php

namespace App\Services;

use App\Models\StreamAlert;
use App\Models\StreamLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Account sharing: a login that started streams from many different addresses in a short time is probably passed around.
 * A user who used at least iptv.sharing_ips different IPs within iptv.sharing_hours gets an alert (at most once a day),
 * which the admin sees, and the reseller too when it is their user. Phones change addresses, so the default is a high 3 in 24 hours.
 */
class SharingDetectionService
{
    /**
     * @return int alerts raised
     */
    public function detect(): int
    {
        $limit = (int) config('iptv.sharing_ips');
        $hours = max(1, (int) config('iptv.sharing_hours'));

        if ($limit < 2) {
            return 0;
        }

        $suspects = StreamLog::where('started_at', '>=', now()->subHours($hours))
            ->select('user_id', DB::raw('count(distinct ip) as ips'))
            ->groupBy('user_id')
            ->havingRaw('count(distinct ip) >= ?', [$limit])
            ->toBase()->get()->mapWithKeys(fn ($row) => [$row->user_id => (int) $row->ips]);

        $users = User::whereIn('id', $suspects->keys())->where('role', 'user')->where('status', 'active')->pluck('username', 'id');
        $raised = 0;

        foreach ($users as $id => $username) {
            // Once a day per user: the cache holds a plain flag
            if (Cache::add("sharing:alerted:{$id}", true, now()->addDay())) {
                StreamAlert::create(['type' => StreamAlert::TYPE_SHARING, 'user_id' => $id, 'name' => $username, 'detail' => $suspects[$id].'|'.$hours]);
                $raised++;
            }
        }

        return $raised;
    }

    /**
     * Where a user watched from lately: each address with its plays and when it was last used.
     *
     * @return list<array{ip: string, plays: int, last: string}>
     */
    public function addresses(User $user, int $days = 7, int $limit = 10): array
    {
        return StreamLog::where('user_id', $user->id)->where('started_at', '>=', now()->subDays($days))
            ->select('ip', DB::raw('count(*) as plays'), DB::raw('max(started_at) as last'))
            ->groupBy('ip')->orderByDesc('last')->limit($limit)->toBase()->get()
            ->map(fn ($row) => ['ip' => (string) $row->ip, 'plays' => (int) $row->plays, 'last' => (string) $row->last])->all();
    }

    /** Different addresses a user streamed from within the detection window. */
    public function recentAddressCount(User $user): int
    {
        return (int) StreamLog::where('user_id', $user->id)->where('started_at', '>=', now()->subHours(max(1, (int) config('iptv.sharing_hours'))))->distinct()->count('ip');
    }
}
