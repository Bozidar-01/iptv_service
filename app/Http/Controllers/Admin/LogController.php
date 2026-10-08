<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
            ->when($request->input('user'), fn ($q, $user) => $q->where('user_id', $user))
            ->orderBy('started_at', 'desc')
            ->paginate(50)
            ->withQueryString();

        $users = ($actor->isAdmin() ? User::where('role', 'user') : User::visibleTo($actor))->orderBy('username')->get(['id', 'username']);

        return view('admin.logs.index', compact('logs', 'users'));
    }
}
