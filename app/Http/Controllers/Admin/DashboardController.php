<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActiveSession;
use App\Models\Channel;
use App\Models\Source;
use App\Models\StreamLog;
use App\Models\User;
use App\Services\HealthService;
use App\Services\StreamService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, HealthService $healthService)
    {
        if ($request->user()->isReseller()) {
            return $this->resellerDashboard($request->user());
        }

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

    /**
     * A reseller's own overview: only their customers, nothing about sources or channels.
     */
    private function resellerDashboard(User $reseller)
    {
        $customers = fn () => User::visibleTo($reseller);
        $ids = User::visibleTo($reseller)->select('id');

        $stats = [
            'customers' => $customers()->count(),
            'active_customers' => $customers()->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('expiration_date')->orWhere('expiration_date', '>', now()))->count(),
            'expiring_soon' => $customers()->where('status', 'active')->whereBetween('expiration_date', [now(), now()->addDays(7)])->count(),
            'expired' => $customers()->where('expiration_date', '<=', now())->count(),
            'watching_now' => ActiveSession::active()->whereIn('user_id', $ids)->count(),
            'plays_today' => StreamLog::where('started_at', '>=', now()->subDay())->whereIn('user_id', $ids)->count(),
        ];

        $expiringSoon = $customers()->where('status', 'active')
            ->whereBetween('expiration_date', [now(), now()->addDays(7)])->orderBy('expiration_date')->limit(8)->get();
        $recentLogs = StreamLog::with(['user', 'channel'])->whereIn('user_id', $ids)->orderByDesc('started_at')->limit(8)->get();

        return view('admin.dashboard-reseller', compact('reseller', 'stats', 'expiringSoon', 'recentLogs'));
    }
}
