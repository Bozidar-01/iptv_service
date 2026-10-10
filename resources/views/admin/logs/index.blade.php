@extends('layouts.admin')

@section('title', __('Activity'))

@section('content')
<div class="page-header">
    <div>
        <h1>{{ __('Activity') }}</h1>
        <p>{{ __('Every time a user starts a channel.') }}</p>
    </div>
</div>

@include('admin.partials.activity-tabs')

<div class="card">
    <form method="GET" action="{{ route('admin.logs.index') }}" class="toolbar">
        <select class="select" name="user" onchange="this.form.submit()" aria-label="{{ __('User') }}">
            <option value="">{{ __('All users') }}</option>
            @foreach($users as $u)
                <option value="{{ $u->id }}" @selected(request('user') == $u->id)>{{ $u->username }}</option>
            @endforeach
        </select>
        @if(request('user'))
            <a href="{{ route('admin.logs.index') }}" class="btn btn-ghost btn-sm"><x-icon name="x" /> {{ __('Clear') }}</a>
        @endif
    </form>

    @if($logs->isEmpty())
        <div class="empty">
            <div class="empty-icon"><x-icon name="activity" /></div>
            <h3>{{ __('No activity yet') }}</h3>
            <p>{{ __('Plays show up here once users start watching.') }}</p>
        </div>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('User') }}</th><th>{{ __('Channel') }}</th><th class="hide-sm">{{ __('IP address') }}</th><th class="hide-sm">{{ __('Player') }}</th><th class="right">{{ __('When') }}</th></tr></thead>
                <tbody>
                @foreach($logs as $log)
                    <tr>
                        <td>
                            @if($log->user)
                                <a href="{{ route('admin.users.show', $log->user) }}" class="cell-main">{{ $log->user->username }}</a>
                            @else
                                <span class="faint">{{ __('Deleted user') }}</span>
                            @endif
                        </td>
                        <td class="truncate">{{ $log->channel->name ?? __('Deleted channel') }}</td>
                        <td class="hide-sm mono muted">{{ $log->ip }}</td>
                        <td class="hide-sm muted truncate" style="max-width: 220px" title="{{ $log->user_agent }}">{{ $log->user_agent }}</td>
                        <td class="right muted" title="{{ $log->started_at }}">{{ $log->started_at->translatedFormat('M j, H:i') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $logs->links('admin.partials.pagination') }}
    @endif
</div>
@endsection
