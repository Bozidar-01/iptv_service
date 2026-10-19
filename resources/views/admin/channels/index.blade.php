@extends('layouts.admin')

@section('title', __('Channels'))

@section('content')
<div class="page-header">
    <div>
        <h1>{{ __('Channels') }}</h1>
        <p>{{ __('Everything users can watch. Disabled channels are hidden from playlists.') }}</p>
    </div>
    <div class="page-actions">
        <form method="POST" action="{{ route('admin.channels.check-streams') }}" class="inline-form" data-confirm="{{ __('Check every enabled channel and disable the ones that do not work? Channels come back on by themselves when their stream works again.') }}">
            @csrf
            @if(is_numeric($filters['source'] ?? null))
                <input type="hidden" name="source_id" value="{{ $filters['source'] }}">
            @endif
            <button class="btn btn-secondary"><x-icon name="signal" /> {{ __('Check streams') }}</button>
        </form>
        <a href="{{ route('admin.sources.index') }}" class="btn btn-secondary"><x-icon name="source" /> {{ __('Import from source') }}</a>
        <a href="{{ route('admin.channels.create') }}" class="btn btn-primary"><x-icon name="plus" /> {{ __('Add channel') }}</a>
    </div>
</div>

<div class="card">
    <form method="GET" action="{{ route('admin.channels.index') }}" class="toolbar" id="filters">
        <div class="input-group">
            <x-icon name="search" />
            <input class="input" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="{{ __('Search channels…') }}">
        </div>
        <select class="select" name="category" onchange="this.form.submit()" aria-label="{{ __('Category') }}">
            <option value="">{{ __('All categories') }}</option>
            <option value="none" @selected(($filters['category'] ?? '') === 'none')>{{ __('Uncategorized') }}</option>
            @foreach($categories as $category)
                <option value="{{ $category->id }}" @selected(($filters['category'] ?? '') == $category->id)>{{ $category->name }}</option>
            @endforeach
        </select>
        <select class="select" name="source" onchange="this.form.submit()" aria-label="{{ __('Source') }}">
            <option value="">{{ __('All sources') }}</option>
            <option value="manual" @selected(($filters['source'] ?? '') === 'manual')>{{ __('Added manually') }}</option>
            @foreach($sources as $source)
                <option value="{{ $source->id }}" @selected(($filters['source'] ?? '') == $source->id)>{{ $source->name }}</option>
            @endforeach
        </select>
        <select class="select" name="bouquet" onchange="this.form.submit()" aria-label="{{ __('Bouquet') }}">
            <option value="">{{ __('Any bouquet') }}</option>
            @foreach($bouquets as $bouquet)
                <option value="{{ $bouquet->id }}" @selected(($filters['bouquet'] ?? '') == $bouquet->id)>{{ __('In :name', ['name' => $bouquet->name]) }}</option>
            @endforeach
        </select>
        <select class="select" name="status" onchange="this.form.submit()" aria-label="{{ __('Status') }}">
            <option value="">{{ __('Any status') }}</option>
            <option value="active" @selected(($filters['status'] ?? '') === 'active')>{{ __('Enabled') }}</option>
            <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>{{ __('Disabled') }}</option>
            <option value="offline" @selected(($filters['status'] ?? '') === 'offline')>{{ __('Not working') }}</option>
            <option value="watched" @selected(($filters['status'] ?? '') === 'watched')>{{ __('Alerts on') }}</option>
            <option value="backup" @selected(($filters['status'] ?? '') === 'backup')>{{ __('Playing a backup') }}</option>
            <option value="slow" @selected(($filters['status'] ?? '') === 'slow')>{{ __('Slow') }}</option>
            <option value="unreliable" @selected(($filters['status'] ?? '') === 'unreliable')>{{ __('Often not working') }}</option>
            <option value="trouble" @selected(($filters['status'] ?? '') === 'trouble')>{{ __('Viewers had trouble') }}</option>
            <option value="backup_copy" @selected(($filters['status'] ?? '') === 'backup_copy')>{{ __('Backup copies') }}</option>
            <option value="unwatched" @selected(($filters['status'] ?? '') === 'unwatched')>{{ __('Not watched in 30 days') }}</option>
        </select>
        @if(array_filter($filters))
            <a href="{{ route('admin.channels.index') }}" class="btn btn-ghost btn-sm"><x-icon name="x" /> {{ __('Clear') }}</a>
        @endif
    </form>

    <form method="POST" action="{{ route('admin.channels.bulk') }}" id="bulk-form" class="bulk-bar" hidden>
        @csrf
        <input type="hidden" name="scope" value="selected" id="bulk-scope">
        @foreach(array_filter($filters) as $key => $value)
            <input type="hidden" name="filters[{{ $key }}]" value="{{ $value }}">
        @endforeach

        <strong id="bulk-count">{{ __(':count selected', ['count' => 0]) }}</strong>
        @if($channels->total() > $channels->count())
            <button type="button" class="btn btn-ghost btn-sm" id="bulk-all" hidden>{{ __('Select all :count matching', ['count' => number_format($channels->total())]) }}</button>
        @endif
        <span class="spacer" style="flex: 1"></span>
        <select class="select" name="action" id="bulk-action" required aria-label="{{ __('Bulk action') }}">
            <option value="">{{ __('Choose action…') }}</option>
            <option value="enable">{{ __('Enable') }}</option>
            <option value="disable">{{ __('Disable') }}</option>
            <option value="add_to_bouquet">{{ __('Add to bouquet') }}</option>
            <option value="remove_from_bouquet">{{ __('Remove from bouquet') }}</option>
            <option value="alerts_on">{{ __('Turn alerts on') }}</option>
            <option value="alerts_off">{{ __('Turn alerts off') }}</option>
            <option value="list_again">{{ __('List again (backup copies)') }}</option>
            <option value="delete">{{ __('Delete') }}</option>
        </select>
        <select class="select" name="bouquet_id" id="bulk-bouquet" hidden aria-label="{{ __('Bouquet') }}">
            <option value="">{{ __('Choose bouquet…') }}</option>
            @foreach($bouquets as $bouquet)
                <option value="{{ $bouquet->id }}">{{ $bouquet->name }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-primary btn-sm">{{ __('Apply') }}</button>
    </form>

    @if($channels->isEmpty())
        <div class="empty">
            <div class="empty-icon"><x-icon name="tv" /></div>
            @if(array_filter($filters))
                <h3>{{ __('No channels match these filters') }}</h3>
                <a href="{{ route('admin.channels.index') }}" class="btn btn-secondary">{{ __('Clear filters') }}</a>
            @else
                <h3>{{ __('No channels yet') }}</h3>
                <p>{{ __('Import them from a provider playlist, or add one by hand.') }}</p>
                <a href="{{ route('admin.sources.create') }}" class="btn btn-primary"><x-icon name="source" /> {{ __('Add a source') }}</a>
            @endif
        </div>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th class="shrink"><input type="checkbox" id="select-page" aria-label="{{ __('Select all on this page') }}" style="accent-color: var(--primary)"></th>
                    <th>{{ __('Channel') }}</th>
                    <th>{{ __('Category') }}</th>
                    <th class="hide-sm">{{ __('Source') }}</th>
                    <th class="hide-sm right">{{ __('Bouquets') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @foreach($channels as $channel)
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="{{ $channel->id }}" form="bulk-form" class="row-check" aria-label="{{ __('Select :name', ['name' => $channel->name]) }}" style="accent-color: var(--primary)"></td>
                        <td>
                            <div class="cell-flex">
                                @if($channel->logo_url)
                                    <img src="{{ $channel->logo_url }}" alt="" class="logo-thumb" loading="lazy" referrerpolicy="no-referrer" onerror="this.style.visibility='hidden'">
                                @else
                                    <span class="logo-fallback">{{ mb_strtoupper(mb_substr($channel->name, 0, 2)) }}</span>
                                @endif
                                <div style="min-width: 0">
                                    <div class="cell-main truncate">{{ $channel->name }}@if($channel->isWatched()) <span title="{{ __('Alerts on') }}"><x-icon name="bell" class="watch-icon" /></span>@endif</div>
                                    <div class="cell-sub truncate mono" style="max-width: 280px">{{ parse_url($channel->stream_url, PHP_URL_HOST) }}</div>
                                </div>
                            </div>
                        </td>
                        <td>{!! $channel->category ? e($channel->category->name) : '<span class="faint">'.e(__('None')).'</span>' !!}</td>
                        <td class="hide-sm">{!! $channel->source ? e($channel->source->name) : '<span class="faint">'.e(__('Manual')).'</span>' !!}</td>
                        <td class="hide-sm right">{{ $channel->bouquets_count }}</td>
                        <td>
                            @if($channel->is_active && $channel->backupFor)
                                <span class="badge badge-dot" title="{{ __('Not in any list. Stands in for :name when it stops working.', ['name' => $channel->backupFor->name]) }}">{{ __('Backup copy') }}</span>
                            @elseif($channel->is_active && $channel->usingBackup)
                                <span class="badge badge-warning badge-dot" title="{{ $channel->stream_error ? \App\Services\StreamCheckService::describeError($channel->stream_error) : __('Too slow') }} · {{ __('Playing :name instead until this stream works again.', ['name' => $channel->usingBackup->name]) }}">{{ __('Playing a backup') }}</span>
                            @elseif($channel->is_active)
                                <span class="badge badge-success badge-dot">{{ __('Enabled') }}</span>
                            @elseif($channel->auto_disabled)
                                <span class="badge badge-danger badge-dot" title="{{ \App\Services\StreamCheckService::describeError($channel->stream_error) }} · {{ __('Disabled by the stream check on :date. It turns back on when the stream works again.', ['date' => $channel->stream_checked_at?->translatedFormat('j M Y H:i')]) }}">{{ __('Not working') }}</span>
                            @else
                                <span class="badge badge-dot">{{ __('Disabled') }}</span>
                            @endif
                            <div class="cell-sub">
                                @php($label = \App\Services\PlaylistOptionsService::label($channel))
                                {{ implode(' · ', array_filter([
                                    $label,
                                    $channel->reliability !== null ? __(':percent% working', ['percent' => $channel->reliability]) : null,
                                    \App\Services\ChannelFailoverService::isSlow($channel->stream_speed) ? __('Slow') : null,
                                    ($trouble[$channel->id] ?? 0) >= \App\Services\ChannelHealthService::TROUBLE_VIEWERS ? __('Viewers had trouble') : null,
                                ])) }}
                            </div>
                        </td>
                        <td class="shrink">
                            <div class="actions">
                                <button type="button" class="btn btn-ghost btn-icon" data-watch="{{ route('admin.channels.preview', $channel, false) }}" data-name="{{ $channel->name }}" data-stream="{{ $channel->stream_url }}" title="{{ __('Watch') }}" aria-label="{{ __('Watch :name', ['name' => $channel->name]) }}"><x-icon name="play" /></button>
                                <a href="{{ route('admin.channels.edit', $channel) }}" class="btn btn-ghost btn-icon" title="{{ __('Edit') }}" aria-label="{{ __('Edit :name', ['name' => $channel->name]) }}"><x-icon name="edit" /></a>
                                <form method="POST" action="{{ route('admin.channels.destroy', $channel) }}" class="inline-form" data-confirm="{{ __('Delete :name?', ['name' => $channel->name]) }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-danger-ghost btn-icon" title="{{ __('Delete') }}" aria-label="{{ __('Delete :name', ['name' => $channel->name]) }}"><x-icon name="trash" /></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $channels->links('admin.partials.pagination') }}
    @endif
</div>

<dialog class="modal modal-wide" id="player-dialog">
    <div class="card-header">
        <h2 id="player-title" class="truncate"></h2>
        <button type="button" class="btn btn-ghost btn-icon" onclick="this.closest('dialog').close()" title="{{ __('Close') }}" aria-label="{{ __('Close') }}"><x-icon name="x" /></button>
    </div>
    <div class="player">
        <video id="player-video" controls playsinline></video>
        <div class="player-status" id="player-status" hidden></div>
    </div>
    <div class="card-body field">
        <label class="label" for="player-url">{{ __('Stream address') }}</label>
        <div class="copy-field">
            <input class="input" id="player-url" readonly onclick="this.select()">
            <button type="button" class="btn btn-secondary" data-copy="#player-url"><x-icon name="copy" /> <span data-copy-label>{{ __('Copy') }}</span></button>
        </div>
        <p class="help">{{ __('If it does not play here, try this address in VLC. Some channels use formats browsers can not play.') }}</p>
    </div>
</dialog>
@endsection

@push('scripts')
<script>
(() => {
    const form = document.getElementById('bulk-form');
    if (!form) return;
    const rows = [...document.querySelectorAll('.row-check')];
    const pageBox = document.getElementById('select-page');
    const count = document.getElementById('bulk-count');
    const scope = document.getElementById('bulk-scope');
    const allBtn = document.getElementById('bulk-all');
    const action = document.getElementById('bulk-action');
    const bouquet = document.getElementById('bulk-bouquet');
    const total = {{ $channels->total() }};
    const text = {{ Js::from([
        'all' => __('All :count matching channels selected'),
        'selected' => __(':count selected'),
        'confirmDelete' => __('Delete :count channels? Imported channels come back on the next sync unless you remove them from the source.'),
    ]) }};
    const fill = (template, n) => template.replace(':count', n.toLocaleString());

    const update = () => {
        const selected = rows.filter((r) => r.checked).length;
        const filtered = scope.value === 'filtered';
        form.hidden = selected === 0 && !filtered;
        count.textContent = filtered ? fill(text.all, total) : fill(text.selected, selected);
        if (pageBox) pageBox.checked = selected === rows.length && rows.length > 0;
        if (allBtn) allBtn.hidden = filtered || selected !== rows.length;
    };

    rows.forEach((r) => r.addEventListener('change', () => { scope.value = 'selected'; update(); }));
    pageBox?.addEventListener('change', () => { rows.forEach((r) => r.checked = pageBox.checked); scope.value = 'selected'; update(); });
    allBtn?.addEventListener('click', () => { scope.value = 'filtered'; update(); });
    action.addEventListener('change', () => {
        const needsBouquet = ['add_to_bouquet', 'remove_from_bouquet'].includes(action.value);
        bouquet.hidden = !needsBouquet;
        bouquet.required = needsBouquet;
    });
    form.addEventListener('submit', (event) => {
        const n = scope.value === 'filtered' ? total : rows.filter((r) => r.checked).length;
        if (action.value === 'delete' && !confirm(fill(text.confirmDelete, n))) {
            event.preventDefault();
        }
    });
})();
</script>
<script>
(() => {
    // Preview player: HLS via hls.js, MPEG-TS via mpegts.js (loaded on first use). Streams go through the panel.
    const dialog = document.getElementById('player-dialog');
    const video = document.getElementById('player-video');
    const status = document.getElementById('player-status');
    const libs = {
        hls: ['https://cdn.jsdelivr.net/npm/hls.js@1.6.2/dist/hls.min.js', () => window.Hls],
        ts: ['https://cdn.jsdelivr.net/npm/mpegts.js@1.8.0/dist/mpegts.min.js', () => window.mpegts],
    };
    const text = {{ Js::from([
        'loading' => __('Loading…'),
        'failed' => __('This channel does not play in the browser. It may be offline, or use a format browsers can not play.'),
        'library' => __('The player could not be loaded. Check your internet connection.'),
    ]) }};
    let player = null;
    let attempt = 0;

    const load = (kind) => {
        const [src, get] = libs[kind];
        if (get()) return Promise.resolve(get());
        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = src;
            script.onload = () => resolve(get());
            script.onerror = reject;
            document.head.appendChild(script);
        });
    };

    const show = (message) => { status.textContent = message; status.hidden = !message; };

    const stop = () => {
        attempt++;
        player?.destroy();
        player = null;
        video.onerror = null;
        video.removeAttribute('src');
        video.load();
    };

    const play = async (url, kinds) => {
        const current = attempt;
        const kind = kinds[0];
        const next = () => {
            if (current !== attempt) return;
            stop();
            kinds.length > 1 ? play(url, kinds.slice(1)) : explain(url);
        };

        let lib;
        try { lib = await load(kind); } catch (e) { return current === attempt && show(text.library); }
        if (current !== attempt) return;

        if (kind === 'hls' && lib.isSupported()) {
            player = new lib({ liveDurationInfinity: true });
            player.on(lib.Events.ERROR, (event, data) => data.fatal && next());
            player.loadSource(url);
            player.attachMedia(video);
        } else if (kind === 'hls' && video.canPlayType('application/vnd.apple.mpegurl')) {
            video.onerror = next;
            video.src = url;
        } else if (kind === 'ts' && lib.isSupported()) {
            player = lib.createPlayer({ type: 'mpegts', isLive: true, url }, { enableWorker: true, liveBufferLatencyChasing: true });
            player.on(lib.Events.ERROR, next);
            player.attachMediaElement(video);
            player.load();
        } else {
            return next();
        }
        video.play().catch(() => {});
    };

    // Show the panel's reason (provider down, error page…) when there is one
    const explain = async (url) => {
        const current = attempt;
        const controller = new AbortController();
        let message = text.failed;
        try {
            const response = await fetch(url, { signal: controller.signal });
            if (!response.ok) message = (await response.text()) || message;
        } catch (e) {}
        controller.abort();
        if (current === attempt) show(message);
    };

    // Live MPEG-TS doesn't always fire "playing", so also hide the message once time moves
    video.addEventListener('playing', () => show(''));
    video.addEventListener('timeupdate', () => video.currentTime > 0 && !video.paused && status.textContent === text.loading && show(''));
    dialog.addEventListener('close', stop);

    document.querySelectorAll('[data-watch]').forEach((button) => button.addEventListener('click', () => {
        stop();
        const url = new URL(button.dataset.watch, location.href).href;
        const path = button.dataset.stream.split('?')[0].toLowerCase();
        document.getElementById('player-title').textContent = button.dataset.name;
        document.getElementById('player-url').value = button.dataset.stream;
        show(text.loading);
        dialog.showModal();
        play(url, path.endsWith('.m3u8') || path.endsWith('.m3u') ? ['hls', 'ts'] : ['ts', 'hls']);
    }));
})();
</script>
@endpush
