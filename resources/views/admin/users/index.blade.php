@extends('layouts.admin')

@section('title', 'User Accounts')

@section('content')
<header class="view-header">
    <div>
        <h2 class="view-title">User<br>Accounts</h2>
        <p class="view-subtitle">Management protocols for IPTV subscription nodes</p>
    </div>
    <div style="display: flex; gap: 16px;">
        <a href="{{ route('admin.users.create') }}" class="action-btn btn-electric shadow-hard">
            <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
            Provision Account
        </a>
    </div>
</header>

@if(session('success'))
    <div class="canvas-card fade-in" style="margin-bottom: 40px; padding: 20px; border-color: rgba(74, 222, 128, 0.3); background: rgba(74, 222, 128, 0.05);">
        <div style="display: flex; align-items: center; gap: 12px; color: var(--success); font-weight: 700; font-size: 0.9rem;">
            <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            {{ session('success') }}
        </div>
    </div>
@endif

<div class="data-table-wrapper shadow-hard">
    <table class="data-table">
        <thead>
            <tr>
                <th>Identity Node</th>
                <th>Protocol Status</th>
                <th>Expiration Vector</th>
                <th>Concurrent Nodes</th>
                <th style="text-align: right;">System Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($users as $user)
                <tr>
                    <td>
                        <div style="display: flex; align-items: center; gap: 20px;">
                            <div class="avatar-sphere" style="width: 50px; height: 50px; border-radius: 14px; font-size: 1.1rem;">
                                {{ strtoupper(substr($user->username, 0, 1)) }}
                            </div>
                            <div>
                                <div style="font-weight: 800; font-size: 1.1rem; color: var(--text-white);">{{ $user->username }}</div>
                                <div style="font-size: 0.7rem; color: var(--text-dim); font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; margin-top: 4px;">UID: {{ str_pad($user->id, 6, '0', STR_PAD_LEFT) }}</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div style="display: flex; flex-direction: column; gap: 8px;">
                            <span class="chip {{ $user->role === 'admin' ? 'chip-cyan' : 'chip-success' }}" style="align-self: flex-start; font-size: 0.65rem;">
                                {{ $user->role }} Authority
                            </span>
                            <form action="{{ route('admin.users.toggle', $user) }}" method="POST">
                                @csrf
                                <button type="submit" style="background: none; border: none; cursor: pointer; padding: 0; text-align: left;">
                                    <span style="font-size: 0.75rem; font-weight: 800; color: {{ $user->isActive() ? 'var(--success)' : 'var(--danger)' }}; text-transform: uppercase; letter-spacing: 0.05em; text-decoration: underline; text-underline-offset: 4px;">
                                        ● {{ $user->status }}
                                    </span>
                                </button>
                            </form>
                        </div>
                    </td>
                    <td>
                        <div style="display: flex; flex-direction: column;">
                            <span style="font-weight: 700; color: {{ $user->isExpired() ? 'var(--danger)' : 'var(--text-main)' }}; font-size: 1rem;">
                                {{ $user->expiration_date ? $user->expiration_date->diffForHumans() : 'Infinite Access' }}
                            </span>
                            @if($user->expiration_date)
                                <span style="font-size: 0.7rem; color: var(--text-dim); font-weight: 800; text-transform: uppercase; margin-top: 4px;">{{ $user->expiration_date->format('M d, Y | H:i') }}</span>
                            @endif
                        </div>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span style="font-size: 1.5rem; font-weight: 900; color: var(--accent); font-family: 'Outfit';">{{ $user->max_connections }}</span>
                            <span style="font-size: 0.65rem; font-weight: 800; color: var(--text-dim); text-transform: uppercase;">Active Slots</span>
                        </div>
                    </td>
                    <td style="text-align: right;">
                        <div style="display: flex; justify-content: flex-end; gap: 12px;">
                            <a href="{{ route('admin.users.edit', $user) }}" class="btn-outline action-btn" style="padding: 10px; border-radius: 10px;">
                                <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </a>
                            @if(Auth::id() !== $user->id)
                                <form action="{{ route('admin.users.destroy', $user) }}" method="POST" onsubmit="return confirm('CRITICAL: Purge account structure for node: {{ $user->username }}?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="action-btn" style="padding: 10px; border-radius: 10px; background: rgba(248, 113, 113, 0.05); border: 1px solid rgba(248, 113, 113, 0.2); color: var(--danger);">
                                        <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="text-align: center; padding: 80px; color: var(--text-dim); font-style: italic; font-weight: 500;">
                        The user directory is currently depleted of active nodes.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div style="margin-top: 40px;">
    {{ $users->links() }}
</div>
@endsection
