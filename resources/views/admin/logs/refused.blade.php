@extends('layouts.admin')

@section('title', __('Refused plays'))

@section('content')
<div class="page-header">
    <div>
        <h1>{{ __('Activity') }}</h1>
        <p>{{ __('Plays the panel refused, with the reason. The same play repeated within a minute is listed once.') }}</p>
    </div>
</div>


<div class="card">
    <form method="GET" action="{{ route('admin.logs.refused') }}" class="toolbar">
        <select class="select" name="reason" onchange="this.form.submit()" aria-label="{{ __('Reason') }}">
            <option value="">{{ __('Any reason') }}</option>
            @foreach($reasons as $key => $label)
                <option value="{{ $key }}" @selected($reason === $key)>{{ $label }}</option>
            @endforeach
        </select>
        <select class="select" name="user" onchange="this.form.submit()" aria-label="{{ __('User') }}">
            <option value="">{{ __('All users') }}</option>
            @foreach($users as $u)
                <option value="{{ $u->id }}" @selected(request('user') == $u->id)>{{ $u->username }}</option>
            @endforeach
        </select>
        @if($reason || request('user'))
            <a href="{{ route('admin.logs.refused') }}" class="btn btn-ghost btn-sm"><x-icon name="x" /> {{ __('Clear') }}</a>
        @endif
    </form>

    @if($denied->isEmpty())
        <div class="empty">
            <div class="empty-icon"><x-icon name="check-circle" /></div>
            <h3>{{ __('Nothing was refused') }}</h3>
            <p>{{ __('Refused plays show up here with the reason, so you can see why something does not play.') }}</p>
        </div>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('User') }}</th><th>{{ __('Channel') }}</th><th>{{ __('Reason') }}</th><th class="hide-sm">{{ __('IP address') }}</th><th class="hide-sm">{{ __('Player') }}</th><th class="right">{{ __('When') }}</th></tr></thead>
                <tbody>
                @foreach($denied as $row)
                    <tr>
                        <td>
                            @if($row->user)
                                <a href="{{ route('admin.users.show', $row->user) }}" class="cell-main">{{ $row->user->username }}</a>
                            @else
                                <span class="faint">{{ __('Deleted user') }}</span>
                            @endif
                        </td>
                        <td class="truncate">{{ $row->channel->name ?? __('Deleted channel') }}</td>
                        <td>
                            <span class="badge badge-warning">{{ $row->label() }}</span>
                            <div class="cell-sub" style="max-width: 320px; white-space: normal">{{ $row->hint() }}</div>
                        </td>
                        <td class="hide-sm mono muted">{{ $row->ip }}</td>
                        <td class="hide-sm muted truncate" style="max-width: 200px" title="{{ $row->user_agent }}">{{ $row->user_agent }}</td>
                        <td class="right muted" title="{{ $row->created_at }}">{{ $row->created_at->translatedFormat('M j, H:i') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $denied->links('admin.partials.pagination') }}
    @endif
</div>
@endsection
