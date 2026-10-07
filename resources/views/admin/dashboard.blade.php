@extends('layouts.admin')

@section('title', __('Dashboard'))

@section('content')
<div class="page-header">
    <div>
        <h1>{{ __('Dashboard') }}</h1>
        <p>{{ __("What's happening on your IPTV service right now.") }}</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('admin.sources.create') }}" class="btn btn-secondary"><x-icon name="source" /> {{ __('Add source') }}</a>
        <a href="{{ route('admin.users.create') }}" class="btn btn-primary"><x-icon name="plus" /> {{ __('New user') }}</a>
    </div>
</div>

@if($sources->isEmpty())
    <div class="alert alert-info">
        <x-icon name="alert" />
        <div class="alert-body">
            <strong>{{ __('Get started:') }}</strong> {{ __("add an M3U source to import channels, put them in a bouquet, then create a user with that bouquet and copy their playlist link into your TV's IPTV app.") }}
            <a href="{{ route('admin.sources.create') }}">{{ __('Add your first source →') }}</a>
        </div>
    </div>
@endif

<div class="stats">
    <div class="card stat">
        <div class="stat-label"><x-icon name="users" /> {{ __('Subscribers') }}</div>
        <div class="stat-value">{{ number_format($stats['active_subscribers']) }}</div>
        <div class="stat-meta">{{ __('active of :total total', ['total' => number_format($stats['subscribers'])]) }}</div>
    </div>
    <div class="card stat">
        <div class="stat-label"><x-icon name="tv" /> {{ __('Channels') }}</div>
        <div class="stat-value">{{ number_format($stats['active_channels']) }}</div>
        <div class="stat-meta">{{ __('enabled of :total total', ['total' => number_format($stats['channels'])]) }}</div>
    </div>
    <div class="card stat">
        <div class="stat-label"><x-icon name="signal" /> {{ __('Watching now') }}</div>
        <div class="stat-value">{{ number_format($stats['watching_now']) }}</div>
        <div class="stat-meta">{{ __('streams in the last 5 minutes') }}</div>
    </div>
    <div class="card stat">
        <div class="stat-label"><x-icon name="play" /> {{ __('Plays') }}</div>
        <div class="stat-value">{{ number_format($stats['plays_today']) }}</div>
        <div class="stat-meta">{{ __('in the last 24 hours') }}</div>
    </div>
</div>

<div class="grid-main">
    <div class="card">
        <div class="card-header">
            <div>
                <h2>{{ __('Recent activity') }}</h2>
                <p>{{ __('Latest channel starts by your users') }}</p>
            </div>
            <a href="{{ route('admin.logs.index') }}" class="btn btn-ghost btn-sm">{{ __('View all') }}</a>
        </div>
        @if($recentLogs->isEmpty())
            <div class="empty">
                <div class="empty-icon"><x-icon name="activity" /></div>
                <h3>{{ __('No activity yet') }}</h3>
                <p>{{ __('Plays show up here once users start watching.') }}</p>
            </div>
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

    <div class="stack">
        <div class="card">
            <div class="card-header">
                <h2>{{ __('Sources') }}</h2>
                <a href="{{ route('admin.sources.index') }}" class="btn btn-ghost btn-sm">{{ __('Manage') }}</a>
            </div>
            @if($sources->isEmpty())
                <div class="empty" style="padding: 28px 20px">
                    <p>{{ __('No sources yet.') }}</p>
                    <a href="{{ route('admin.sources.create') }}" class="btn btn-secondary btn-sm"><x-icon name="plus" /> {{ __('Add source') }}</a>
                </div>
            @else
                <ul class="list">
                    @foreach($sources as $source)
                        <li>
                            <div style="min-width: 0">
                                <div class="cell-main truncate">{{ $source->name }}</div>
                                <div class="cell-sub">
                                    {{ trans_choice(':count channel|:count channels', $source->channel_count, ['count' => number_format($source->channel_count)]) }}
                                    @if($source->last_synced_at) · {{ __('synced :time', ['time' => $source->last_synced_at->diffForHumans()]) }} @endif
                                </div>
                            </div>
                            @include('admin.partials.source-status', ['source' => $source])
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="card">
            <div class="card-header">
                <h2>{{ __('Expiring within 7 days') }}</h2>
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
    </div>
</div>
@endsection
