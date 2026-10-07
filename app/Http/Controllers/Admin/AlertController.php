<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StreamAlert;

class AlertController extends Controller
{
    public function index()
    {
        $alerts = StreamAlert::latest('id')->paginate(50);

        return view('admin.alerts.index', compact('alerts'));
    }

    public function markAllRead()
    {
        StreamAlert::unread()->update(['read_at' => now()]);

        return back();
    }
}
