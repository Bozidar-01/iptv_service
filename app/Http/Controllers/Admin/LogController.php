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
        $logs = StreamLog::with(['user', 'channel'])
            ->when($request->input('user'), fn ($q, $user) => $q->where('user_id', $user))
            ->orderBy('started_at', 'desc')
            ->paginate(50)
            ->withQueryString();

        $users = User::where('role', 'user')->orderBy('username')->get(['id', 'username']);

        return view('admin.logs.index', compact('logs', 'users'));
    }
}
