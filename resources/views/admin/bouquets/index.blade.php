@extends('layouts.admin')

@section('title', 'Subscription Packs')

@section('content')
<header class="view-header">
    <div>
        <h2 class="view-title">Subscription<br>Packs</h2>
        <p class="view-subtitle">Consolidated channel bundles and access definitions</p>
    </div>
    <div style="display: flex; gap: 16px;">
        <a href="{{ route('admin.bouquets.create') }}" class="action-btn btn-electric shadow-hard">
            <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Assemble Pack
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

<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)); gap: 40px;">
    @forelse($bouquets as $bouquet)
        <div class="canvas-card fade-in" style="padding: 40px; border-top: 4px solid var(--secondary); background: linear-gradient(180deg, var(--bg-surface) 0%, rgba(129, 102, 248, 0.02) 100%);">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 32px;">
                <div style="padding: 12px; background: rgba(129, 102, 248, 0.1); border-radius: 12px; border: 1px solid rgba(129, 102, 248, 0.2);">
                    <svg style="width: 32px; height: 32px; color: var(--secondary);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                </div>
                <div style="display: flex; gap: 8px;">
                    <a href="{{ route('admin.bouquets.edit', $bouquet) }}" class="btn-outline action-btn" style="padding: 10px; border-radius: 10px; color: var(--secondary); border-color: rgba(129, 102, 248, 0.2);">
                        <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    </a>
                    <form action="{{ route('admin.bouquets.destroy', $bouquet) }}" method="POST" onsubmit="return confirm('CRITICAL: Delete bundle definition for pack: {{ $bouquet->name }}?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="action-btn" style="padding: 10px; border-radius: 10px; background: rgba(248, 113, 113, 0.05); border: 1px solid rgba(248, 113, 113, 0.2); color: var(--danger);">
                            <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                    </form>
                </div>
            </div>

            <div style="margin-bottom: 40px;">
                <h3 style="font-size: 2rem; font-weight: 800; letter-spacing: -0.04em; line-height: 1.1; margin-bottom: 8px;">{{ $bouquet->name }}</h3>
                <span class="chip chip-cyan" style="font-size: 0.6rem; letter-spacing: 0.1em;">Standard Deployment</span>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; border-top: 1px solid var(--border); padding-top: 32px;">
                <div>
                    <div style="font-size: 0.7rem; font-weight: 800; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 8px;">LINKED SIGNALS</div>
                    <div style="font-size: 1.75rem; font-weight: 900; color: var(--text-white); font-family: 'Outfit';">{{ $bouquet->channels_count }}</div>
                </div>
                <div>
                    <div style="font-size: 0.7rem; font-weight: 800; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 8px;">SUBSCRIBED NODES</div>
                    <div style="font-size: 1.75rem; font-weight: 900; color: var(--text-white); font-family: 'Outfit';">{{ $bouquet->users_count }}</div>
                </div>
            </div>
        </div>
    @empty
        <div style="grid-column: 1 / -1; text-align: center; padding: 100px; border: 1px dashed var(--border); border-radius: var(--radius-lg); color: var(--text-dim);">
            No subscription bundles configured in the infrastructure.
        </div>
    @endforelse
</div>
@endsection
