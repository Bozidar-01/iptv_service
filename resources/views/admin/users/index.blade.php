@extends('layouts.admin')

@section('title', 'Users')

@section('content')
<div class="page-header">
    <div>
        <h1>Users</h1>
        <p>Subscribers who watch through their playlist link, and admins who manage this panel.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('admin.users.create') }}" class="btn btn-primary"><x-icon name="plus" /> New user</a>
    </div>
</div>

<div class="card">
    <form method="GET" action="{{ route('admin.users.index') }}" class="toolbar">
        <div class="input-group">
            <x-icon name="search" />
            <input class="input" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Search by username…">
        </div>
        <select class="select" name="status" onchange="this.form.submit()" aria-label="Status">
            <option value="">All statuses</option>
            <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
            <option value="expired" @selected(($filters['status'] ?? '') === 'expired')>Expired</option>
            <option value="disabled" @selected(($filters['status'] ?? '') === 'disabled')>Disabled</option>
        </select>
        @if(array_filter($filters))
            <a href="{{ route('admin.users.index') }}" class="btn btn-ghost btn-sm"><x-icon name="x" /> Clear</a>
        @endif
    </form>

    @if($users->isEmpty())
        <div class="empty">
            <div class="empty-icon"><x-icon name="users" /></div>
            <h3>{{ array_filter($filters) ? 'No users match' : 'No users yet' }}</h3>
            @unless(array_filter($filters))
                <p>Create a user, give them a bouquet, and share their playlist link.</p>
                <a href="{{ route('admin.users.create') }}" class="btn btn-primary"><x-icon name="plus" /> New user</a>
            @endunless
        </div>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th>User</th><th>Status</th><th class="hide-sm">Expires</th><th class="hide-sm right">Connections</th><th></th></tr>
                </thead>
                <tbody>
                @foreach($users as $user)
                    <tr>
                        <td>
                            <div class="cell-flex">
                                <span class="avatar">{{ strtoupper(substr($user->username, 0, 1)) }}</span>
                                <div style="min-width: 0">
                                    <a href="{{ route('admin.users.show', $user) }}" class="cell-main">{{ $user->username }}</a>
                                    @if($user->isAdmin()) <span class="badge badge-primary">Admin</span> @endif
                                    <div class="cell-sub truncate">
                                        {{ $user->bouquets->pluck('name')->join(', ') ?: 'No bouquets' }}
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>@include('admin.partials.user-status', ['user' => $user])</td>
                        <td class="hide-sm muted">
                            @if($user->expiration_date)
                                <span title="{{ $user->expiration_date->toDateString() }}">{{ $user->expiration_date->format('M j, Y') }}</span>
                            @else
                                Never
                            @endif
                        </td>
                        <td class="hide-sm right">{{ $user->max_connections }}</td>
                        <td class="shrink">
                            <div class="actions">
                                <a href="{{ route('admin.users.show', $user) }}" class="btn btn-secondary btn-sm"><x-icon name="link" /> Playlist</a>
                                <form method="POST" action="{{ route('admin.users.toggle', $user) }}" class="inline-form">
                                    @csrf
                                    <button class="btn btn-ghost btn-icon" title="{{ $user->status === 'active' ? 'Disable' : 'Enable' }}" aria-label="{{ $user->status === 'active' ? 'Disable' : 'Enable' }} {{ $user->username }}"><x-icon name="power" /></button>
                                </form>
                                <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-ghost btn-icon" title="Edit" aria-label="Edit {{ $user->username }}"><x-icon name="edit" /></a>
                                @unless($user->is(auth()->user()))
                                    <form method="POST" action="{{ route('admin.users.destroy', $user) }}" class="inline-form" data-confirm="Delete {{ $user->username }}? Their playlist stops working immediately.">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-danger-ghost btn-icon" title="Delete" aria-label="Delete {{ $user->username }}"><x-icon name="trash" /></button>
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
