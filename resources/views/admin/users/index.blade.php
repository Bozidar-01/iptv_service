@extends('layouts.admin')

@section('title', __('Users'))

@section('content')
<div class="page-header">
    <div>
        <h1>{{ __('Users') }}</h1>
        <p>{{ __('Subscribers who watch through their playlist link, and admins who manage this panel.') }}</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('admin.users.trial') }}" class="btn btn-secondary"><x-icon name="clock" /> {{ __('New trial') }}</a>
        <a href="{{ route('admin.users.create') }}" class="btn btn-primary"><x-icon name="plus" /> {{ __('New user') }}</a>
    </div>
</div>

<div class="card">
    <form method="GET" action="{{ route('admin.users.index') }}" class="toolbar">
        <div class="input-group">
            <x-icon name="search" />
            <input class="input" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="{{ __('Search by username…') }}">
        </div>
        <select class="select" name="status" onchange="this.form.submit()" aria-label="{{ __('Status') }}">
            <option value="">{{ __('All statuses') }}</option>
            <option value="active" @selected(($filters['status'] ?? '') === 'active')>{{ __('Active') }}</option>
            <option value="expired" @selected(($filters['status'] ?? '') === 'expired')>{{ __('Expired') }}</option>
            <option value="disabled" @selected(($filters['status'] ?? '') === 'disabled')>{{ __('Disabled') }}</option>
        </select>
        @if(array_filter($filters))
            <a href="{{ route('admin.users.index') }}" class="btn btn-ghost btn-sm"><x-icon name="x" /> {{ __('Clear') }}</a>
        @endif
    </form>

    @if($users->isEmpty())
        <div class="empty">
            <div class="empty-icon"><x-icon name="users" /></div>
            <h3>{{ array_filter($filters) ? __('No users match') : __('No users yet') }}</h3>
            @unless(array_filter($filters))
                <p>{{ __('Create a user, give them a bouquet, and share their playlist link.') }}</p>
                <a href="{{ route('admin.users.create') }}" class="btn btn-primary"><x-icon name="plus" /> {{ __('New user') }}</a>
            @endunless
        </div>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th>{{ __('User') }}</th><th>{{ __('Status') }}</th><th class="hide-sm">{{ __('Expires') }}</th><th class="hide-sm right">{{ __('Connections') }}</th><th></th></tr>
                </thead>
                <tbody>
                @foreach($users as $user)
                    <tr>
                        <td>
                            <div class="cell-flex">
                                <span class="avatar">{{ strtoupper(substr($user->username, 0, 1)) }}</span>
                                <div style="min-width: 0">
                                    <a href="{{ route('admin.users.show', $user) }}" class="cell-main">{{ $user->username }}</a>
                                    @if($user->isAdmin()) <span class="badge badge-primary">{{ __('Admin') }}</span> @endif
                                    @if($user->isReseller()) <span class="badge badge-info">{{ __('Reseller') }}</span> @endif
                                    <div class="cell-sub truncate">
                                        {{ $user->bouquets->pluck('name')->join(', ') ?: __('No bouquets') }}
                                        @if($user->owner && Auth::user()->isAdmin()) · {{ __('Reseller: :name', ['name' => $user->owner->username]) }} @endif
                                        @if($user->isReseller()) · {{ $user->usesCredits() ? __(':count credits', ['count' => number_format($user->credits)]) : __('Unlimited credits') }} @endif
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>@include('admin.partials.user-status', ['user' => $user])</td>
                        <td class="hide-sm muted">
                            @if($user->expiration_date)
                                <span title="{{ $user->expiration_date->toDateString() }}">{{ $user->expiration_date->translatedFormat('M j, Y') }}</span>
                            @else
                                {{ __('Never') }}
                            @endif
                        </td>
                        <td class="hide-sm right">{{ $user->max_connections }}</td>
                        <td class="shrink">
                            <div class="actions">
                                <a href="{{ route('admin.users.show', $user) }}" class="btn btn-secondary btn-sm"><x-icon name="link" /> {{ __('Playlist') }}</a>
                                <form method="POST" action="{{ route('admin.users.toggle', $user) }}" class="inline-form">
                                    @csrf
                                    <button class="btn btn-ghost btn-icon" title="{{ $user->status === 'active' ? __('Disable') : __('Enable') }}" aria-label="{{ $user->status === 'active' ? __('Disable :name', ['name' => $user->username]) : __('Enable :name', ['name' => $user->username]) }}"><x-icon name="power" /></button>
                                </form>
                                <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-ghost btn-icon" title="{{ __('Edit') }}" aria-label="{{ __('Edit :name', ['name' => $user->username]) }}"><x-icon name="edit" /></a>
                                @unless($user->is(auth()->user()))
                                    <form method="POST" action="{{ route('admin.users.destroy', $user) }}" class="inline-form" data-confirm="{{ __('Delete :name? Their playlist stops working immediately.', ['name' => $user->username]) }}">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-danger-ghost btn-icon" title="{{ __('Delete') }}" aria-label="{{ __('Delete :name', ['name' => $user->username]) }}"><x-icon name="trash" /></button>
                                    </form>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $users->links('admin.partials.pagination') }}
    @endif
</div>
@endsection
