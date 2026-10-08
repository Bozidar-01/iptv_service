<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StreamAlert;
use App\Models\User;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    public function index(Request $request)
    {
        $actor = $request->user();

        // A reseller sees the alerts about their own customers (expiring, expired, asking to renew)
        $alerts = StreamAlert::latest('id')
            ->when(!$actor->isAdmin(), fn ($q) => $q->whereIn('user_id', User::visibleTo($actor)->select('id')))
            ->paginate(50);

        return view('admin.alerts.index', compact('alerts'));
    }

    public function markAllRead(Request $request)
    {
        // Read / unread is the admins' to-do list; a reseller's click changes nothing
        if ($request->user()->isAdmin()) {
            StreamAlert::unread()->update(['read_at' => now()]);
        }

        return back();
    }
}
