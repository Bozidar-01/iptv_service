@extends('layouts.admin')

@section('title', 'Activity Logs')

@section('content')
<header class="view-header">
    <div>
        <h2 class="view-title">Activity<br>Logs</h2>
        <p class="view-subtitle">Real-time signal interception and connection telemetry</p>
    </div>
    <div style="display: flex; gap: 16px;">
         <div class="canvas-card" style="padding: 12px 24px; display: flex; align-items: center; gap: 12px; border-color: var(--accent); background: var(--accent-dim);">
            <div style="width: 10px; height: 10px; border-radius: 50%; background: var(--accent); box-shadow: 0 0 10px var(--accent); animation: pulse-node 2s infinite;"></div>
            <span style="font-size: 0.75rem; font-weight: 800; color: var(--accent); text-transform: uppercase; letter-spacing: 0.1em;">Live Monitoring</span>
         </div>
    </div>
</header>

<div class="data-table-wrapper shadow-hard">
    <table class="data-table">
        <thead>
            <tr>
                <th>Traffic Node</th>
                <th>Signal Carrier</th>
                <th>Telemetry Info</th>
                <th>Elapsed Sync</th>
                <th style="text-align: right;">Time Vector</th>
            </tr>
        </thead>
        <tbody>
            @forelse($logs as $log)
                <tr class="fade-in">
                    <td>
                        <div style="display: flex; align-items: center; gap: 16px;">
                            <div class="avatar-sphere" style="width: 36px; height: 36px; font-size: 0.8rem; border-radius: 10px;">
                                {{ strtoupper(substr($log->user->username ?? '?', 0, 1)) }}
                            </div>
                            <div>
                                <div style="font-weight: 700; font-size: 0.95rem; color: var(--text-white);">{{ $log->user->username ?? 'External' }}</div>
                                <div style="font-size: 0.65rem; color: var(--text-dim); font-weight: 800; text-transform: uppercase;">Node: {{ $log->user_id }}</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="padding: 6px; background: rgba(129, 102, 248, 0.1); border-radius: 6px; border: 1px solid rgba(129, 102, 248, 0.2);">
                                <svg style="width: 14px; height: 14px; color: var(--secondary);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                            </div>
                            <span style="font-weight: 800; color: var(--text-white); font-size: 0.9rem;">{{ $log->channel->name ?? 'Terminated Signal' }}</span>
                        </div>
                    </td>
                    <td>
                        <div style="display: flex; flex-direction: column; gap: 6px;">
                            <span style="font-family: monospace; font-size: 0.75rem; color: var(--accent); font-weight: 700;">{{ $log->ip }}</span>
                            <span style="font-size: 0.65rem; color: var(--text-dim); font-weight: 600; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $log->user_agent }}">
                                {{ $log->user_agent }}
                            </span>
                        </div>
                    </td>
                    <td>
                        @if($log->ended_at)
                             <div style="display: flex; align-items: center; gap: 8px;">
                                <div style="width: 4px; height: 4px; border-radius: 50%; background: var(--text-dim);"></div>
                                <span style="font-size: 0.85rem; font-weight: 700; color: var(--text-muted);">{{ $log->started_at->diffInMinutes($log->ended_at) }}m synced</span>
                             </div>
                        @else
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div style="width: 6px; height: 6px; border-radius: 50%; background: var(--success); box-shadow: 0 0 8px var(--success);"></div>
                                <span style="font-size: 0.85rem; font-weight: 800; color: var(--success);">Active Link</span>
                             </div>
                        @endif
                    </td>
                    <td style="text-align: right;">
                        <div style="font-weight: 900; color: var(--text-white); font-size: 1rem; font-family: 'Outfit';">{{ $log->started_at->format('H:i:s') }}</div>
                        <div style="font-size: 0.7rem; color: var(--text-dim); font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 4px;">{{ $log->started_at->format('M d, Y') }}</div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="text-align: center; padding: 80px; color: var(--text-dim); font-style: italic; font-weight: 500;">
                        The activity manifest is currently devoid of signals.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div style="margin-top: 40px;">
    {{ $logs->links() }}
</div>

<style>
@keyframes pulse-node {
    0% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.4; transform: scale(1.1); }
    100% { opacity: 1; transform: scale(1); }
}
</style>
@endsection
