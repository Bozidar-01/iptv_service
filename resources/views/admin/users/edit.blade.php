@extends('layouts.admin')

@section('title', 'Sync Node Profile')

@section('content')
<div style="max-width: 1000px; margin: 0 auto;">
    <header class="view-header">
        <div>
            <h2 class="view-title">Maintain<br>Node</h2>
            <p class="view-subtitle">Synchronizing profile metadata for account: <span style="color: var(--accent); font-weight: 800;">{{ $user->username }}</span></p>
        </div>
        <div style="display: flex; gap: 16px;">
            <a href="{{ route('admin.users.index') }}" class="btn-outline action-btn">
                <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                Active Manifest
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

    <form action="{{ route('admin.users.update', $user) }}" method="POST" class="canvas-card shadow-hard fade-in" style="padding: 60px;">
        @csrf
        @method('PUT')
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-bottom: 48px;">
            <div>
                <h4 style="font-size: 0.75rem; font-weight: 800; color: var(--accent); text-transform: uppercase; letter-spacing: 0.15em; margin-bottom: 32px;">Security Credentials</h4>
                
                <div style="display: flex; flex-direction: column; gap: 24px;">
                    <div>
                        <label class="input-label">System Identity (Username)</label>
                        <input type="text" name="username" required value="{{ old('username', $user->username) }}" class="input-field">
                    </div>
                    <div>
                        <label class="input-label">Re-initialize Token (Password)</label>
                        <input type="password" name="password" class="input-field" placeholder="Leave blank to maintain current link">
                    </div>
                </div>
            </div>

            <div>
                <h4 style="font-size: 0.75rem; font-weight: 800; color: var(--secondary); text-transform: uppercase; letter-spacing: 0.15em; margin-bottom: 32px;">Deployment Parameters</h4>
                
                <div style="display: flex; flex-direction: column; gap: 24px;">
                    <div>
                        <label class="input-label">Link Capacity (Max Connections)</label>
                        <input type="number" name="max_connections" required value="{{ old('max_connections', $user->max_connections) }}" min="1" class="input-field" style="font-family: 'Outfit'; font-size: 1.25rem;">
                    </div>
                    <div>
                        <label class="input-label">Expiration Timeline</label>
                        <input type="datetime-local" name="expiration_date" value="{{ old('expiration_date', $user->expiration_date ? $user->expiration_date->format('Y-m-d\TH:i') : '') }}" class="input-field">
                    </div>
                </div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-bottom: 48px;">
             <div>
                <h4 style="font-size: 0.75rem; font-weight: 800; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.15em; margin-bottom: 24px;">Authorization Authority</h4>
                <select name="role" class="input-field" style="appearance: none;">
                    <option value="user" {{ $user->role === 'user' ? 'selected' : '' }}>Standard Policy Subscriber</option>
                    <option value="admin" {{ $user->role === 'admin' ? 'selected' : '' }}>Tier-1 Systems Administrator</option>
                </select>
            </div>
            <div>
                <h4 style="font-size: 0.75rem; font-weight: 800; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.15em; margin-bottom: 24px;">Network Status</h4>
                <select name="status" class="input-field" style="appearance: none;">
                    <option value="active" {{ $user->status === 'active' ? 'selected' : '' }}>Node Active - Valid Signal</option>
                    <option value="disabled" {{ $user->status === 'disabled' ? 'selected' : '' }}>Node Inhibited - Signal Blocked</option>
                </select>
            </div>
        </div>

        <div>
            <h4 style="font-size: 0.75rem; font-weight: 800; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.15em; margin-bottom: 24px;">Mapped Pack Access (Bouquets)</h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px;">
                @foreach($bouquets as $bouquet)
                    <label style="cursor: pointer;">
                        <input type="checkbox" name="bouquets[]" value="{{ $bouquet->id }}" style="display: none;" class="peer-pack" {{ $user->bouquets->contains($bouquet->id) ? 'checked' : '' }}>
                        <div class="btn-outline" style="padding: 20px; border-radius: 14px; display: flex; flex-direction: column; gap: 4px; transition: all 0.2s ease;">
                            <div style="font-weight: 800; font-size: 0.95rem; color: var(--text-white);">{{ $bouquet->name }}</div>
                            <div style="font-size: 0.7rem; color: var(--text-dim); font-weight: 700; text-transform: uppercase;">{{ $bouquet->channels_count }} Signals</div>
                        </div>
                    </label>
                @endforeach
            </div>
        </div>

        <div style="margin-top: 64px; display: flex; gap: 20px; border-top: 1px solid var(--border); padding-top: 48px;">
            <button type="submit" class="action-btn btn-electric shadow-hard" style="flex: 2; padding: 24px; font-size: 1.1rem; text-transform: uppercase; letter-spacing: 0.2em;">
                Synchronize Configuration
            </button>
            <a href="{{ route('admin.users.index') }}" class="action-btn btn-outline" style="flex: 1; padding: 24px; font-size: 1rem; color: var(--text-dim);">
                Dismiss Changes
            </a>
        </div>
    </form>
</div>

<style>
    .peer-pack:checked + div { border-color: var(--secondary); background: rgba(129, 102, 248, 0.1); }
</style>
@endsection
