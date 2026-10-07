<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Channel;
use App\Models\Bouquet;
use App\Models\StreamLog;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        $stats = [
            'total_users' => User::where('role', 'user')->count(),
            'active_channels' => Channel::where('is_active', true)->count(),
            'total_bouquets' => Bouquet::count(),
            'recent_logs_count' => StreamLog::where('started_at', '>=', now()->subDay())->count(),
        ];

        $recentLogs = StreamLog::with(['user', 'channel'])
            ->orderBy('started_at', 'desc')
            ->limit(10)
            ->get();

        return view('admin.dashboard', compact('stats', 'recentLogs'));
    }
}
