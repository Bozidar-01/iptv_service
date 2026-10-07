@extends('layouts.admin')

@section('title', 'Modify Signal')

@section('content')
<div style="max-width: 1000px; margin: 0 auto;">
    <header class="view-header">
        <div>
            <h2 class="view-title">Modify<br>Signal</h2>
            <p class="view-subtitle">Synchronizing broadcast parameters for: <span style="color: var(--success); font-weight: 800;">{{ $channel->name }}</span></p>
        </div>
        <div style="display: flex; gap: 16px;">
            <a href="{{ route('admin.channels.index') }}" class="btn-outline action-btn">
                <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Channel Directory
            </a>
        </div>
    </header>

    @if($errors->any())
        <div class="canvas-card fade-in" style="margin-bottom: 40px; padding: 24px; border-color: rgba(248, 113, 113, 0.3); background: rgba(248, 113, 113, 0.05);">
            <div style="display: flex; flex-direction: column; gap: 12px; color: var(--danger); font-weight: 700; font-size: 0.9rem;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    DANGER: Integrity Violation
                </div>
                <ul style="list-style: none; padding-left: 28px; font-weight: 600; font-size: 0.85rem;">
                    @foreach($errors->all() as $error)
                        <li>• {{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <form action="{{ route('admin.channels.update', $channel) }}" method="POST" class="canvas-card shadow-hard fade-in" style="padding: 60px;">
        @csrf
        @method('PUT')
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-bottom: 48px;">
            <div>
                <h4 style="font-size: 0.75rem; font-weight: 800; color: var(--accent); text-transform: uppercase; letter-spacing: 0.15em; margin-bottom: 32px;">Carrier Metadata</h4>
                
                <div style="display: flex; flex-direction: column; gap: 24px;">
                    <div>
                        <label class="input-label">Broadcaster Title</label>
                        <input type="text" name="name" required value="{{ old('name', $channel->name) }}" class="input-field">
                    </div>
                    <div>
                        <label class="input-label">Categorization Handle</label>
                        <select name="category_id" required class="input-field" style="appearance: none;">
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" {{ $channel->category_id == $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <div>
                <h4 style="font-size: 0.75rem; font-weight: 800; color: var(--secondary); text-transform: uppercase; letter-spacing: 0.15em; margin-bottom: 32px;">Visual Branding</h4>
                
                <div style="display: flex; flex-direction: column; gap: 24px;">
                    <div>
                        <label class="input-label">Identity Hologram (Logo URL)</label>
                        <input type="url" name="logo_url" value="{{ old('logo_url', $channel->logo_url) }}" class="input-field">
                    </div>
                    <div style="display: flex; align-items: flex-end; padding-bottom: 12px;">
                        <label style="display: flex; align-items: center; cursor: pointer; gap: 12px; font-weight: 800; font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.1em;">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" {{ $channel->is_active ? 'checked' : '' }} style="width: 24px; height: 24px; accent-color: var(--success); cursor: pointer;">
                            BROADCASTING NODE ACTIVE
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <div style="margin-bottom: 48px;">
            <h4 style="font-size: 0.75rem; font-weight: 800; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.15em; margin-bottom: 24px;">Upstream Signal Relay (HLS/TS/MP4)</h4>
            <div style="background: var(--bg-base); border: 2px dashed var(--border); padding: 48px; border-radius: 20px; text-align: center;">
                <input type="text" name="stream_url" required value="{{ old('stream_url', $channel->stream_url) }}" class="input-field" style="font-family: monospace; border-style: none; background: transparent; padding: 0; font-size: 1.25rem; color: var(--accent); text-align: center;">
                <p style="font-size: 0.7rem; color: var(--text-dim); margin-top: 24px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em;">Sync with master relay protocols</p>
            </div>
        </div>

        <div style="margin-top: 64px; display: flex; gap: 20px; border-top: 1px solid var(--border); padding-top: 48px;">
            <button type="submit" class="action-btn btn-electric shadow-hard" style="flex: 2; padding: 24px; font-size: 1.1rem; text-transform: uppercase; letter-spacing: 0.2em;">
                Synchronize Pulse
            </button>
            <a href="{{ route('admin.channels.index') }}" class="action-btn btn-outline" style="flex: 1; padding: 24px; font-size: 1rem; color: var(--text-dim);">
                Dismiss Sync
            </a>
        </div>
    </form>
</div>
@endsection
