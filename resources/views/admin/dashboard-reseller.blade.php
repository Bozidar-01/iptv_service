@extends('layouts.admin')

@section('title', __('Dashboard'))

@section('content')
<div class="page-header">
    <div>
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
    <div class="card stat">
        <div class="stat-label"><x-icon name="users" /> {{ __('Your users') }}</div>
        <div class="stat-value">{{ number_format($stats['active_customers']) }}</div>
        <div class="stat-meta">{{ __('active of :total total', ['total' => number_format($stats['customers'])]) }}</div>
    </div>
    <div class="card stat">
        <div class="stat-label"><x-icon name="clock" /> {{ __('Expiring within 7 days') }}</div>
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
    <div class="card stat">
        <div class="stat-label"><x-icon name="signal" /> {{ __('Watching now') }}</div>
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
            <div class="empty" style="padding: 28px 20px"><p>{{ __('No subscriptions expiring soon.') }}</p></div>
        @else
            <ul class="list">
                @foreach($expiringSoon as $user)
                    <li>
                        <a href="{{ route('admin.users.show', $user) }}" class="cell-main">{{ $user->username }}</a>
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
            <div class="empty" style="padding: 28px 20px"><p>{{ __('Plays show up here once users start watching.') }}</p></div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('User') }}</th><th>{{ __('Channel') }}</th><th class="right">{{ __('When') }}</th></tr></thead>
                    <tbody>
                    @foreach($recentLogs as $log)
                        <tr>
                            <td>
                                @if($log->user)
                                    <a href="{{ route('admin.users.show', $log->user) }}" class="cell-main">{{ $log->user->username }}</a>
                                @else
                                    <span class="faint">{{ __('Deleted user') }}</span>
                                @endif
                            </td>
                            <td class="truncate">{{ $log->channel->name ?? __('Deleted channel') }}</td>
                            <td class="right muted" title="{{ $log->started_at }}">{{ $log->started_at->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
