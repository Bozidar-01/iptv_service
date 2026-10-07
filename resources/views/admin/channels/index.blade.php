@extends('layouts.admin')

@section('title', 'Stream Directory')

@section('content')
<header class="view-header">
    <div>
        <h2 class="view-title">Stream<br>Directory</h2>
        <p class="view-subtitle">Primary broadcasting endpoints and carrier metadata</p>
    </div>
    <div style="display: flex; gap: 16px;">
        <a href="{{ route('admin.channels.create') }}" class="action-btn btn-electric shadow-hard">
            <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0h-3m-9-1h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            Add New Signal
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
                <th>Carrier Branding</th>
                <th>Content Group</th>
                <th>Signal Status</th>
                <th>Relay Endpoint</th>
                <th style="text-align: right;">System Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($channels as $channel)
                <tr>
                    <td>
                        <div style="display: flex; align-items: center; gap: 20px;">
                            <div style="width: 100px; height: 60px; background: #000; border-radius: var(--radius-md); border: 1px solid var(--border); overflow: hidden; display: flex; align-items: center; justify-content: center; padding: 8px;">
                                @if($channel->logo_url)
                                    <img src="{{ $channel->logo_url }}" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                                @else
                                    <span style="font-size: 0.6rem; font-weight: 900; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em;">No Branding</span>
                                @endif
                            </div>
                            <div>
                                <div style="font-weight: 800; font-size: 1.1rem; color: var(--text-white);">{{ $channel->name }}</div>
                                <div style="font-size: 0.7rem; color: var(--text-dim); font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; margin-top: 4px;">Node ID: #{{ str_pad($channel->id, 4, '0', STR_PAD_LEFT) }}</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="chip chip-cyan" style="font-size: 0.65rem;">
                            {{ $channel->category->name ?? 'Unmapped' }}
                        </span>
                    </td>
                    <td>
                        <span class="chip {{ $channel->is_active ? 'chip-success' : 'chip-danger' }}" style="font-size: 0.65rem;">
                            ● {{ $channel->is_active ? 'Streaming' : 'Offline' }}
                        </span>
                    </td>
                    <td>
                        <div style="font-family: monospace; font-size: 0.75rem; color: var(--accent); background: rgba(0,0,0,0.3); padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border); max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $channel->stream_url }}">
                            {{ $channel->stream_url }}
                        </div>
                    </td>
                    <td style="text-align: right;">
                        <div style="display: flex; justify-content: flex-end; gap: 12px;">
                            <a href="{{ route('admin.channels.edit', $channel) }}" class="btn-outline action-btn" style="padding: 10px; border-radius: 10px;">
                                <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </a>
                            <form action="{{ route('admin.channels.destroy', $channel) }}" method="POST" onsubmit="return confirm('DANGER: Permanent signal termination for channel: {{ $channel->name }}?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="action-btn" style="padding: 10px; border-radius: 10px; background: rgba(248, 113, 113, 0.05); border: 1px solid rgba(248, 113, 113, 0.2); color: var(--danger);">
                                    <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="text-align: center; padding: 80px; color: var(--text-dim); font-style: italic; font-weight: 500;">
                        No signal endpoints registered in the directory.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div style="margin-top: 40px;">
    {{ $channels->links() }}
</div>
@endsection
