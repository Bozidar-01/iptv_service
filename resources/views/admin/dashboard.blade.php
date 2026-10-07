@extends('layouts.admin')

@section('title', 'Network Health')

@section('content')
<header class="view-header">
    <div>
        <h2 class="view-title">Network<br>Health</h2>
        <p class="view-subtitle">Infrastructure status is currently <span style="color: var(--success); font-weight: 800;">OPTIMAL</span></p>
    </div>
    <div style="display: flex; gap: 16px;">
        <a href="{{ route('admin.channels.create') }}" class="action-btn btn-electric">
            <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Deploy Node
        </a>
    </div>
</header>

<div class="metric-grid">
    <div class="canvas-card metric-tile">
        <span class="metric-label">Managed Entries</span>
        <div class="metric-value">{{ $stats['total_users'] }}</div>
        <div class="metric-trend" style="color: var(--success)">
            <svg style="width: 14px; height: 14px;" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M12 7a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0V8.414l-4.293 4.293a1 1 0 01-1.414 0L8 10.414l-4.293 4.293a1 1 0 01-1.414-1.414l5-5a1 1 0 011.414 0L11 10.586 14.586 7H12z" clip-rule="evenodd"/></svg>
            +2.4% vs last week
        </div>
    </div>

    <div class="canvas-card metric-tile">
        <span class="metric-label">Active Carriers</span>
        <div class="metric-value">{{ $stats['active_channels'] }}</div>
        <div class="metric-trend" style="color: var(--accent)">
            <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            Live broadcast sync
        </div>
    </div>

    <div class="canvas-card metric-tile">
        <span class="metric-label">Mapped Bundles</span>
        <div class="metric-value">{{ $stats['total_bouquets'] }}</div>
        <div class="metric-trend" style="color: var(--secondary)">
            <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
            Ecosystem structure
        </div>
    </div>

    <div class="canvas-card metric-tile">
        <span class="metric-label">Stream Activity</span>
        <div class="metric-value">{{ $stats['recent_logs_count'] }}</div>
        <div class="metric-trend" style="color: var(--warning)">
            <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Real-time tracking
        </div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1.8fr 1fr; gap: 40px;">
    <!-- Recent Logs Visualization -->
    <div class="canvas-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px;">
            <h3 style="font-size: 1.25rem; font-weight: 800;">Real-Time Activity</h3>
            <a href="{{ route('admin.logs.index') }}" style="color: var(--accent); font-size: 0.75rem; font-weight: 800; text-decoration: none; text-transform: uppercase; letter-spacing: 0.1em;">Inspection Panel →</a>
        </div>
        
        <div style="display: flex; flex-direction: column; gap: 12px;">
            @forelse($recentLogs as $log)
                <div style="padding: 16px; background: rgba(0, 0, 0, 0.2); border: 1px solid var(--border); border-radius: var(--radius-md); display: flex; align-items: center; gap: 16px;">
                    <div style="width: 8px; height: 8px; border-radius: 50%; background: var(--accent); box-shadow: 0 0 8px var(--accent);"></div>
                    <div style="flex: 1;">
                        <span style="font-weight: 800; font-size: 0.85rem; color: var(--text-white);">{{ $log->user->username ?? 'Unknown' }}</span>
                        <span style="color: var(--text-dim); font-size: 0.85rem; margin: 0 8px;">is streaming</span>
                        <span style="font-weight: 800; font-size: 0.85rem; color: var(--secondary);">{{ $log->channel->name ?? 'Stream node' }}</span>
                    </div>
                    <div style="font-size: 0.7rem; font-weight: 700; color: var(--text-dim); text-transform: uppercase;">{{ $log->started_at->diffForHumans() }}</div>
                </div>
            @empty
                <div style="text-align: center; padding: 48px; color: var(--text-dim); font-style: italic;">No active signals detected.</div>
            @endforelse
        </div>
    </div>

    <!-- System Control Center -->
    <div style="display: flex; flex-direction: column; gap: 32px;">
        <div class="canvas-card">
            <h3 style="font-size: 1.1rem; font-weight: 800; margin-bottom: 24px;">Core Modals</h3>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                 <a href="{{ route('admin.users.create') }}" class="btn-outline action-btn" style="padding: 16px; border-radius: 12px;">+ User</a>
                 <a href="{{ route('admin.channels.create') }}" class="btn-outline action-btn" style="padding: 16px; border-radius: 12px;">+ Stream</a>
                 <a href="{{ route('admin.bouquets.create') }}" class="btn-outline action-btn" style="padding: 16px; border-radius: 12px;">+ Pack</a>
                 <a href="{{ route('admin.categories.index') }}" class="btn-outline action-btn" style="padding: 16px; border-radius: 12px;">+ Group</a>
            </div>
        </div>

        <div class="canvas-card">
            <h3 style="font-size: 1.1rem; font-weight: 800; margin-bottom: 20px;">System Nodes</h3>
            <div style="display: flex; flex-direction: column; gap: 20px;">
                <div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 8px;">
                        <span style="color: var(--text-muted);">Uptime Control</span>
                        <span style="color: var(--success);">Stable</span>
                    </div>
                    <div style="height: 4px; background: rgba(0,0,0,0.3); border-radius: 2px; overflow: hidden;">
                        <div style="width: 98%; height: 100%; background: var(--success);"></div>
                    </div>
                </div>
                <div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 8px;">
                        <span style="color: var(--text-muted);">Database Sync</span>
                        <span style="color: var(--accent);">Optimal</span>
                    </div>
                    <div style="height: 4px; background: rgba(0,0,0,0.3); border-radius: 2px; overflow: hidden;">
                        <div style="width: 100%; height: 100%; background: var(--accent);"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
