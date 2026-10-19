@extends('layouts.admin')

@section('title', __('Watching now'))

@section('content')
<div class="page-header">
    <div>
        <h1>{{ __('Watching now') }}</h1>
        <p>{{ __('Who is watching right now: devices that played something in the last :minutes minutes.', ['minutes' => \App\Services\StreamService::SESSION_TIMEOUT_MINUTES]) }}</p>
    </div>
    <div class="page-actions">
        <label class="check" style="align-items: center">
            <input type="checkbox" id="auto-refresh" checked>
            <div><strong>{{ __('Refresh every 20 seconds') }}</strong></div>
        </label>
    </div>
</div>

<div class="stats">
    <div class="card stat">
        <div class="stat-label"><x-icon name="monitor" /> {{ __('Devices watching') }}</div>
        <div class="stat-value">{{ number_format($sessions->count()) }}</div>
        <div class="stat-meta">{{ trans_choice(':count user|:count users', $viewers, ['count' => $viewers]) }}</div>
    </div>
    <div class="card stat">
        <div class="stat-label"><x-icon name="tv" /> {{ __('Most watched now') }}</div>
        <div class="stat-value" style="font-size: 20px">{{ $topChannel ?? '–' }}</div>
        <div class="stat-meta">{{ $topChannel ? trans_choice(':count device|:count devices', $topChannelDevices, ['count' => $topChannelDevices]) : __('nobody yet') }}</div>
    </div>
</div>

<div class="card">
    @if($sessions->isEmpty())
        <div class="empty">
            <div class="empty-icon"><x-icon name="eye" /></div>
            <h3>{{ __('Nobody is watching right now') }}</h3>
            <p>{{ __('Devices show up here while they play.') }}</p>
        </div>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('User') }}</th><th>{{ __('Channel') }}</th><th class="hide-sm">{{ __('IP address') }}</th><th class="hide-sm">{{ __('Player') }}</th><th class="right">{{ __('Since') }}</th><th class="shrink"></th></tr></thead>
                <tbody>
                @foreach($sessions as $session)
                    <tr>
                        <td>
                            @if($session->user)
                                <a href="{{ route('admin.users.show', $session->user) }}" class="cell-main">{{ $session->user->username }}</a>
                            @else
                                <span class="faint">{{ __('Deleted user') }}</span>
                            @endif
                        </td>
                        <td class="truncate">{{ $session->channel->name ?? __('Deleted channel') }}</td>
                        <td class="hide-sm mono muted">{{ $session->ip }}</td>
                        <td class="hide-sm muted truncate" style="max-width: 200px" title="{{ $session->user_agent }}">{{ $session->user_agent }}</td>
                        <td class="right muted" title="{{ $session->started_at }}">{{ $session->started_at->diffForHumans() }}</td>
                        <td>
                            @if($session->user)
                                <form method="POST" action="{{ route('admin.users.sessions.disconnect', [$session->user, $session]) }}" data-confirm="{{ __('Disconnect this device? It can not start a stream for :minutes minutes.', ['minutes' => \App\Services\StreamService::DEVICE_BLOCK_MINUTES]) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-danger-ghost btn-sm">{{ __('Disconnect') }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
(() => {
    const box = document.getElementById('auto-refresh');
    let saved = null;
    try { saved = localStorage.getItem('live-refresh'); } catch (e) {}
    if (saved === 'off') box.checked = false;
    box.addEventListener('change', () => { try { localStorage.setItem('live-refresh', box.checked ? 'on' : 'off'); } catch (e) {} });
    setInterval(() => { if (box.checked && !document.hidden) location.reload(); }, 20000);
})();
</script>
@endpush
