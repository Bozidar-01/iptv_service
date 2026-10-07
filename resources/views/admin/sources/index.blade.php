@extends('layouts.admin')

@section('title', __('Sources'))

@section('content')
<div class="page-header">
    <div>
        <h1>{{ __('Sources') }}</h1>
        <p>{{ __('M3U playlists from your providers. Their channels are imported and kept up to date.') }}</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('admin.sources.create') }}" class="btn btn-primary"><x-icon name="plus" /> {{ __('Add source') }}</a>
    </div>
</div>

@if($sources->contains(fn ($s) => $s->status === 'queued' && $s->updated_at->lt(now()->subMinutes(2))))
    <div class="alert alert-warning">
        <x-icon name="alert" />
        <div class="alert-body">
            {!! __('A sync has been queued for over 2 minutes. Make sure the queue worker is running: :command (or :alt).', ['command' => '<code>php artisan queue:work</code>', 'alt' => '<code>composer dev</code>']) !!}
        </div>
    </div>
@endif

<div class="card">
    @if($sources->isEmpty())
        <div class="empty">
            <div class="empty-icon"><x-icon name="source" /></div>
            <h3>{{ __('No sources yet') }}</h3>
            <p>{{ __("Add a provider's M3U URL or upload a playlist file to import channels.") }}</p>
            <a href="{{ route('admin.sources.create') }}" class="btn btn-primary"><x-icon name="plus" /> {{ __('Add source') }}</a>
        </div>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>{{ __('Source') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th class="right">{{ __('Channels') }}</th>
                    <th class="hide-sm">{{ __('Bouquet') }}</th>
                    <th class="hide-sm">{{ __('Last sync') }}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @foreach($sources as $source)
                    <tr>
                        <td>
                            <div class="cell-flex">
                                <span class="logo-fallback"><x-icon :name="$source->type === 'url' ? 'globe' : 'upload'" style="width:15px;height:15px" /></span>
                                <div style="min-width: 0">
                                    <div class="cell-main">{{ $source->name }}</div>
                                    <div class="cell-sub truncate">
                                        {{ $source->type === 'url' ? parse_url($source->url, PHP_URL_HOST) : __('Uploaded file') }}
                                        @if($source->skip_vod) · {{ __('live only') }} @endif
                                        @if($source->auto_sync) · {{ __('auto-refresh daily') }} @endif
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            @include('admin.partials.source-status', ['source' => $source])
                            @if($source->status === 'failed' && $source->last_error)
                                <div class="cell-sub truncate" style="max-width: 220px; color: var(--danger)" title="{{ $source->last_error }}">{{ $source->last_error }}</div>
                            @endif
                        </td>
                        <td class="right">
                            <a href="{{ route('admin.channels.index', ['source' => $source->id]) }}" class="cell-main">{{ number_format($source->channel_count) }}</a>
                            <div class="cell-sub">{{ __(':count enabled', ['count' => number_format($source->active_channels_count)]) }}</div>
                        </td>
                        <td class="hide-sm">
                            @if($source->bouquet)
                                <span class="badge badge-primary">{{ $source->bouquet->name }}</span>
                            @else
                                <span class="faint">{{ __('None') }}</span>
                            @endif
                        </td>
                        <td class="hide-sm muted">{{ $source->last_synced_at?->diffForHumans() ?? __('Never') }}</td>
                        <td class="shrink">
                            <div class="actions">
                                <form method="POST" action="{{ route('admin.sources.sync', $source) }}" class="inline-form">
                                    @csrf
                                    <button class="btn btn-secondary btn-sm" @disabled($source->isBusy())><x-icon name="refresh" /> {{ __('Sync') }}</button>
                                </form>
                                <a href="{{ route('admin.sources.edit', $source) }}" class="btn btn-ghost btn-icon" title="{{ __('Edit') }}" aria-label="{{ __('Edit :name', ['name' => $source->name]) }}"><x-icon name="edit" /></a>
                                <form method="POST" action="{{ route('admin.sources.destroy', $source) }}" class="inline-form"
                                      data-confirm="{{ __("Delete :name and its :count channels? This can't be undone.", ['name' => $source->name, 'count' => number_format($source->channel_count)]) }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-danger-ghost btn-icon" title="{{ __('Delete') }}" aria-label="{{ __('Delete :name', ['name' => $source->name]) }}"><x-icon name="trash" /></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

@if($sources->contains->isBusy())
    @push('scripts')
        <script>setTimeout(() => location.reload(), 5000);</script>
    @endpush
@endif
@endsection
