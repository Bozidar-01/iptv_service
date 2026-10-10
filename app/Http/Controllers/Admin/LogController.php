<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeniedPlay;
use App\Models\StreamLog;
use App\Models\User;
use Illuminate\Http\Request;

class LogController extends Controller
{
    public function index(Request $request)
    {
        $actor = $request->user();

        $logs = StreamLog::with(['user', 'channel'])
            // A reseller sees the plays of their own customers only
            ->when(!$actor->isAdmin(), fn ($q) => $q->whereIn('user_id', User::visibleTo($actor)->select('id')))
            ->when($request->input('user'), fn ($q, $user) => is_scalar($user) ? $q->where('user_id', $user) : $q)
            ->orderBy('started_at', 'desc')
            ->paginate(50)
            ->withQueryString();

        $users = ($actor->isAdmin() ? User::where('role', 'user') : User::visibleTo($actor))->orderBy('username')->get(['id', 'username']);

        return view('admin.logs.index', compact('logs', 'users'));
    }

    /** Plays that were refused, with the reason. A reseller sees their own customers only. */
    public function refused(Request $request)
    {
        $actor = $request->user();
        $reason = is_string($request->input('reason')) && in_array($request->input('reason'), DeniedPlay::REASONS, true) ? $request->input('reason') : null;

        $denied = DeniedPlay::with(['user:id,username', 'channel:id,name'])
            ->whereIn('user_id', User::visibleTo($actor)->select('id'))
            ->when($reason, fn ($q) => $q->where('reason', $reason))
            ->when($request->input('user'), fn ($q, $user) => is_scalar($user) ? $q->where('user_id', $user) : $q)
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        $users = User::visibleTo($actor)->orderBy('username')->get(['id', 'username']);

        return view('admin.logs.refused', ['denied' => $denied, 'users' => $users, 'reasons' => DeniedPlay::labels(), 'reason' => $reason]);
    }
}
