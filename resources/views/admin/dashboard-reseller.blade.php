@extends('layouts.admin')

@section('title', __('Dashboard'))

@section('content')
<div class="page-header">
    <div>
        <span class="eyebrow">{{ now()->translatedFormat('l, j F') }}</span>
        <h1>{{ __('Dashboard') }}</h1>
        <p>{{ __('Your users and what they are watching.') }}</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('admin.users.create') }}" class="btn btn-primary"><x-icon name="plus" /> {{ __('New user') }}</a>
    </div>
</div>

@if($reseller->max_customers !== null)
    <div class="alert {{ $stats['customers'] >= $reseller->max_customers ? 'alert-warning' : 'alert-info' }}">
        <x-icon name="alert" />
        <div class="alert-body">{{ __('You have :count of :max users.', ['count' => number_format($stats['customers']), 'max' => number_format($reseller->max_customers)]) }}</div>
    </div>
@endif

<div class="stats">
    <div class="card stat stat-link">
        <a href="{{ route('admin.users.index') }}" class="stat-label"><x-icon name="users" /> {{ __('Your users') }} <x-icon name="chevron-right" class="stat-chevron" /></a>
        <div class="stat-value">{{ number_format($stats['active_customers']) }}</div>
        <div class="stat-meta">{{ __('active of :total total', ['total' => number_format($stats['customers'])]) }}</div>
    </div>
    <div class="card stat stat-link">
        <a href="{{ route('admin.users.index', ['status' => 'active']) }}" class="stat-label"><x-icon name="clock" /> {{ __('Expiring within 7 days') }} <x-icon name="chevron-right" class="stat-chevron" /></a>
        <div class="stat-value">{{ number_format($stats['expiring_soon']) }}</div>
        <div class="stat-meta">{{ __(':count already expired', ['count' => number_format($stats['expired'])]) }}</div>
    </div>
    <div class="card stat">
        <div class="stat-label"><x-icon name="package" /> {{ __('Your credits') }}</div>
        <div class="stat-value">{{ $reseller->usesCredits() ? number_format($reseller->credits) : __('Unlimited') }}</div>
        <div class="stat-meta">
            @if($reseller->usesCredits())
                {{ __('1 credit = 1 month for one user') }} · <a href="{{ route('admin.credits.index') }}">{{ __('History') }}</a>
            @else
                {{ __('not limited by credits') }}
            @endif
        </div>
    </div>
    <div class="card stat stat-link">
        <a href="{{ route('admin.live.index') }}" class="stat-label"><x-icon name="signal" /> {{ __('Watching now') }} <x-icon name="chevron-right" class="stat-chevron" /></a>
        <div class="stat-value">{{ number_format($stats['watching_now']) }}</div>
        <div class="stat-meta">{{ __(':count plays in the last 24 hours', ['count' => number_format($stats['plays_today'])]) }}</div>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header">
            <h2>{{ __('Expiring within 7 days') }}</h2>
            <a href="{{ route('admin.users.index', ['status' => 'active']) }}" class="btn btn-ghost btn-sm">{{ __('View all') }}</a>
        </div>
        @if($expiringSoon->isEmpty())
            <div class="empty compact"><p>{{ __('No subscriptions expiring soon.') }}</p></div>
        @else
            <ul class="list">
                @foreach($expiringSoon as $user)
                    <li class="item">
                        <span class="avatar avatar-sm">{{ mb_strtoupper(mb_substr($user->username, 0, 1)) }}</span>
                        <a href="{{ route('admin.users.show', $user) }}" class="item-body cell-main truncate">{{ $user->username }}</a>
                        <span class="badge badge-warning">{{ $user->expiration_date->diffForHumans() }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="card">
        <div class="card-header">
            <h2>{{ __('Recent activity') }}</h2>
            <a href="{{ route('admin.logs.index') }}" class="btn btn-ghost btn-sm">{{ __('View all') }}</a>
        </div>
        @if($recentLogs->isEmpty())
            <div class="empty compact"><p>{{ __('Plays show up here once users start watching.') }}</p></div>
        @else
            <ul class="list">
                @foreach($recentLogs as $log)
                    <li class="item">
                        @if($log->channel?->logo_url)
                            <img src="{{ $log->channel->logo_url }}" alt="" class="logo-thumb" loading="lazy" referrerpolicy="no-referrer" onerror="this.style.visibility='hidden'">
                        @else
                            <span class="logo-fallback">{{ mb_strtoupper(mb_substr($log->channel->name ?? '?', 0, 2)) }}</span>
                        @endif
                        <div class="item-body">
                            <div class="cell-main truncate">{{ $log->channel->name ?? __('Deleted channel') }}</div>
                            <div class="cell-sub">
                                @if($log->user)
                                    <a href="{{ route('admin.users.show', $log->user) }}">{{ $log->user->username }}</a>
                                @else
                                    {{ __('Deleted user') }}
                                @endif
                            </div>
                        </div>
                        <span class="item-end faint" title="{{ $log->started_at }}">{{ $log->started_at->diffForHumans() }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
@endsection
