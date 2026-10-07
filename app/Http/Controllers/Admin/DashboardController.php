<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActiveSession;
use App\Models\Channel;
use App\Models\Source;
use App\Models\StreamAlert;
use App\Models\StreamLog;
use App\Models\User;
use App\Services\HealthService;
use App\Services\StreamService;

class DashboardController extends Controller
{
    public function index(HealthService $healthService)
    {
        $health = $healthService->status();

        $subscribers = User::where('role', 'user');

        $stats = [
            'subscribers' => (clone $subscribers)->count(),
            'active_subscribers' => (clone $subscribers)->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('expiration_date')->orWhere('expiration_date', '>', now()))
                ->count(),
            'channels' => Channel::count(),
            'active_channels' => Channel::where('is_active', true)->count(),
            'watching_now' => ActiveSession::where('last_activity_at', '>', now()->subMinutes(StreamService::SESSION_TIMEOUT_MINUTES))->count(),
            'plays_today' => StreamLog::where('started_at', '>=', now()->subDay())->count(),
        ];

        $sources = Source::orderBy('name')->get();

        // Watched channels whose last check failed
        $down = Channel::watched()->where('stream_status', 'offline')->where(fn ($q) => $q->where('is_active', true)->orWhere('auto_disabled', true));
        $downWatched = [
            'count' => (clone $down)->count(),
            'channels' => (clone $down)->with('source')->orderByDesc('stream_checked_at')->limit(8)->get(),
            'failed_sources' => Source::where('alert_on_down', true)->where('status', Source::STATUS_FAILED)->orderBy('name')->get(),
        ];
        $stats['not_working'] = Channel::where('auto_disabled', true)->count();

        $expiringSoon = (clone $subscribers)
            ->where('status', 'active')
            ->whereBetween('expiration_date', [now(), now()->addDays(7)])
            ->orderBy('expiration_date')
            ->limit(5)
            ->get();

        $recentLogs = StreamLog::with(['user', 'channel'])
            ->orderBy('started_at', 'desc')
            ->limit(8)
            ->get();

        return view('admin.dashboard', compact('stats', 'sources', 'expiringSoon', 'recentLogs', 'downWatched', 'health'));
    }
}
