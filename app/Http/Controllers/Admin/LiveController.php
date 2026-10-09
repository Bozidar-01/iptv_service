<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActiveSession;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Who is watching right now: the devices that played something in the last few minutes. An admin sees everyone,
 * a reseller their own users.
 */
class LiveController extends Controller
{
    public function index(Request $request)
    {
        $sessions = ActiveSession::active()
            ->with(['user:id,username,owner_id', 'channel:id,name'])
            ->whereIn('user_id', User::visibleTo($request->user())->select('id'))
            ->orderByDesc('last_activity_at')
            ->limit(500)
            ->get();

        $topChannel = $sessions->groupBy('channel_id')->map->count()->sortDesc()->keys()->first();

        return view('admin.live.index', [
            'sessions' => $sessions,
            'viewers' => $sessions->pluck('user_id')->unique()->count(),
            'topChannel' => $topChannel ? $sessions->firstWhere('channel_id', $topChannel)?->channel?->name : null,
            'topChannelDevices' => $topChannel ? $sessions->where('channel_id', $topChannel)->count() : 0,
        ]);
    }
}
