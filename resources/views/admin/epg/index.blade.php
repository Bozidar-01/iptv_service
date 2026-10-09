@extends('layouts.admin')

@section('title', __('TV guide'))

@section('content')
<div class="page-header">
    <div>
        <h1>{{ __('TV guide') }}</h1>
        <p>{{ __('XMLTV guide files. Channels are matched to them automatically, and TV apps show what is on now and next.') }}</p>
    </div>
    <div class="page-actions">
        <form method="POST" action="{{ route('admin.epg.match') }}" class="inline-form">
            @csrf
            <button class="btn btn-secondary"><x-icon name="refresh" /> {{ __('Match channels again') }}</button>
        </form>
    </div>
</div>

<div class="stats">
    <div class="card stat">
        <div class="stat-label"><x-icon name="tv" /> {{ __('Enabled channels with a guide') }}</div>
        <div class="stat-value">{{ number_format($coverage['matched']) }}</div>
        <div class="stat-meta">{{ __('of :total enabled', ['total' => number_format($coverage['enabled'])]) }}</div>
    </div>
    <div class="card stat">
        <div class="stat-label"><x-icon name="clock" /> {{ __('With upcoming programmes') }}</div>
        <div class="stat-value">{{ number_format($coverage['with_programmes']) }}</div>
        <div class="stat-meta">{{ __('the rest have a match but no data right now') }}</div>
    </div>
</div>

@if($suggestions)
    <div class="card" style="margin-bottom: 20px">
        <div class="card-header">
            <div>
                <h2>{{ __('Guides your channels could use') }}</h2>
                <p>{{ __('These countries have channels without a guide, and a free guide file exists for each. Only the programmes of channels you have are kept.') }}</p>
            </div>
            <form method="POST" action="{{ route('admin.epg.countries') }}" class="inline-form" data-confirm="{{ trans_choice('Add :count guide and import it in the background?|Add :count guides and import them in the background?', count($suggestions), ['count' => count($suggestions)]) }}">
                @csrf
                <input type="hidden" name="all" value="1">
                <button class="btn btn-primary"><x-icon name="plus" /> {{ __('Add all :count', ['count' => count($suggestions)]) }}</button>
            </form>
        </div>
        <ul class="list">
            @foreach($suggestions as $suggestion)
                <li>
                    <div>
                        <strong>{{ $suggestion['name'] }}</strong>
                        <div class="muted">{{ trans_choice(':count channel without a guide|:count channels without a guide', $suggestion['channels'], ['count' => number_format($suggestion['channels'])]) }}</div>
                    </div>
                    <form method="POST" action="{{ route('admin.epg.countries') }}" class="inline-form">
                        @csrf
                        <input type="hidden" name="countries[]" value="{{ $suggestion['code'] }}">
                        <button class="btn btn-secondary btn-sm"><x-icon name="plus" /> {{ __('Add') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
    </div>
@endif

<div class="card">
    <div class="card-header">
        <div>
            <h2>{{ __('Guides') }}</h2>
            <p>{{ __('Refreshed every night. When two guides have the same channel, the one for the channel\'s own country is used, else the one added first.') }}</p>
        </div>
    </div>
    @if($sources->isEmpty())
        <div class="empty" style="padding: 28px 20px"><p>{{ __('No guides yet. Add one below.') }}</p></div>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>{{ __('Guide') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th class="right hide-sm">{{ __('Channels') }}</th>
                    <th class="right">{{ __('Programmes') }}</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @foreach($sources as $source)
                    <tr>
                        <td>
                            <div class="cell-main">{{ $source->name }}</div>
                            <div class="cell-sub truncate mono" style="max-width: 320px">{{ parse_url($source->url, PHP_URL_HOST) }}{{ parse_url($source->url, PHP_URL_PATH) }}</div>
                        </td>
                        <td>
                            @include('admin.partials.source-status', ['source' => $source])
                            @if($source->status === 'failed' && $source->last_error)
                                <div class="cell-sub truncate" style="max-width: 220px; color: var(--danger)" title="{{ $source->last_error }}">{{ $source->last_error }}</div>
                            @elseif($source->last_synced_at)
                                <div class="cell-sub">{{ $source->last_synced_at->diffForHumans() }}</div>
                            @endif
                        </td>
                        <td class="right hide-sm">{{ number_format($source->channel_count) }}</td>
                        <td class="right">{{ number_format($source->programme_count) }}</td>
                        <td class="shrink">
                            <div class="actions">
                                <form method="POST" action="{{ route('admin.epg.sync', $source) }}" class="inline-form">
                                    @csrf
                                    <button class="btn btn-ghost btn-icon" title="{{ __('Import now') }}" aria-label="{{ __('Import :name now', ['name' => $source->name]) }}" @disabled($source->isBusy())><x-icon name="refresh" /></button>
                                </form>
                                <form method="POST" action="{{ route('admin.epg.destroy', $source) }}" class="inline-form" data-confirm="{{ __('Delete the guide :name?', ['name' => $source->name]) }}">
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

<form method="POST" action="{{ route('admin.epg.store') }}" class="card">
    @csrf
    <div class="card-header"><h2>{{ __('Add a guide') }}</h2></div>
    <div class="card-body form-grid">
        <div class="field">
            <label class="label" for="name">{{ __('Name') }}</label>
            <input class="input" id="name" name="name" value="{{ old('name') }}" required placeholder="{{ __('e.g. Germany') }}">
        </div>
        <div class="field">
            <label class="label" for="url">{{ __('XMLTV URL') }}</label>
            <input class="input mono" id="url" name="url" type="url" value="{{ old('url') }}" required placeholder="https://iptv-epg.org/files/epg-de.xml.gz">
        </div>
        <span class="help full">{!! __('Plain or .gz XMLTV files work. Free guides per country: :site.', ['site' => '<code>https://iptv-epg.org/files/epg-&lt;country&gt;.xml.gz</code>']) !!}</span>
    </div>
    <div class="card-footer">
        <button class="btn btn-primary"><x-icon name="plus" /> {{ __('Add guide') }}</button>
    </div>
</form>
@endsection
