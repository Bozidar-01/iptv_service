@extends('layouts.admin')

@section('title', 'Assemble Pack')

@section('content')
<div style="max-width: 1000px; margin: 0 auto;">
    <header class="view-header">
        <div>
            <h2 class="view-title">Assemble<br>Pack</h2>
            <p class="view-subtitle">Curation of signal bundles for subscription deployment</p>
        </div>
        <div style="display: flex; gap: 16px;">
            <a href="{{ route('admin.bouquets.index') }}" class="btn-outline action-btn">
                <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Active Packs
            </a>
        </div>
    </header>

    @include('admin.partials.errors')

    <form action="{{ route('admin.bouquets.store') }}" method="POST" class="canvas-card shadow-hard fade-in" style="padding: 60px;">
        @csrf
        
        <div style="margin-bottom: 48px;">
            <label class="input-label">Human-Readable Bundle Title</label>
            <input type="text" name="name" required value="{{ old('name') }}" class="input-field" style="font-size: 2rem; font-weight: 800; font-family: 'Outfit'; letter-spacing: -0.04em;" placeholder="e.g. ULTIMATE CINEMA PACK">
        </div>

        <div>
            <h4 style="font-size: 0.75rem; font-weight: 800; color: var(--accent); text-transform: uppercase; letter-spacing: 0.15em; margin-bottom: 24px;">Signal Mapping Directory</h4>
            <div style="background: var(--bg-base); border: 2px dashed var(--border); padding: 32px; border-radius: 20px;">
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; max-height: 400px; overflow-y: auto; padding-right: 12px;">
                    @foreach($channels as $channel)
                        <label style="cursor: pointer;">
                            <input type="checkbox" name="channels[]" value="{{ $channel->id }}" style="display: none;" class="peer-sig">
                            <div class="btn-outline" style="padding: 16px; border-radius: 12px; display: flex; align-items: center; gap: 12px; transition: all 0.2s ease; border-width: 1px;">
                                <div style="width: 32px; height: 20px; background: #000; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 0.5rem; font-weight: 900; color: var(--text-dim);">
                                    @if($channel->logo_url)
                                        <img src="{{ $channel->logo_url }}" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                                    @else
                                        SIG
                                    @endif
                                </div>
                                <div style="font-weight: 700; font-size: 0.85rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--text-main);">
                                    {{ $channel->name }}
                                </div>
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>
            <p style="font-size: 0.7rem; color: var(--text-dim); margin-top: 16px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; text-align: center;">Select all carriers to be included in this subscription node</p>
        </div>

        <div style="margin-top: 64px; display: flex; gap: 20px; border-top: 1px solid var(--border); padding-top: 48px;">
            <button type="submit" class="action-btn btn-electric shadow-hard" style="flex: 2; padding: 24px; font-size: 1.1rem; text-transform: uppercase; letter-spacing: 0.2em;">
                Inaugurate Bundle
            </button>
            <a href="{{ route('admin.bouquets.index') }}" class="action-btn btn-outline" style="flex: 1; padding: 24px; font-size: 1rem; color: var(--text-dim);">
                Dismiss Build
            </a>
        </div>
    </form>
</div>

<style>
    .peer-sig:checked + div { border-color: var(--accent); background: var(--accent-dim); color: var(--accent); }
    .peer-sig:checked + div div { color: var(--accent) !important; }
</style>
@endsection
