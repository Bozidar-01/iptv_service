@extends('layouts.admin')

@section('title', $source->name)

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route('admin.sources.index') }}" class="breadcrumb"><x-icon name="arrow-left" /> {{ __('Sources') }}</a>
        <h1>{{ $source->name }}</h1>
        <p>{{ __('What the receiver\'s relay says right now, and how to shorten its channel list.') }}</p>
    </div>
    <div class="page-actions">
        <form method="POST" action="{{ route('admin.receiver.refresh', $source) }}" class="inline-form">
            @csrf
            <button class="btn btn-secondary"><x-icon name="refresh" /> {{ __('Refresh') }}</button>
        </form>
    </div>
</div>

<div class="stack">
    @if($status['error'])
        <div class="alert alert-danger"><x-icon name="alert" /><div class="alert-body">{{ $status['error'] }}</div></div>
    @endif

    <div class="stats">
        <div class="card stat">
            <div class="stat-label"><x-icon name="signal" /> {{ __('Receiver') }}</div>
            <div class="stat-value" style="font-size: 22px">{{ $status['online'] ? __('Online') : __('Offline') }}</div>
            <div class="stat-meta">{{ $status['version'] ? __('Relay version :version', ['version' => $status['version']]) : '–' }}</div>
        </div>
        <div class="card stat">
            <div class="stat-label"><x-icon name="tv" /> {{ __('Tuners in use') }}</div>
            <div class="stat-value">{{ $status['active_tuners'] ?? '–' }} <span class="faint" style="font-size: 16px">/ {{ $status['max_streams'] ?: '–' }}</span></div>
            <div class="stat-meta">{{ trans_choice(':count channel playing|:count channels playing', $status['active_channels'], ['count' => $status['active_channels']]) }}</div>
        </div>
        <div class="card stat">
            <div class="stat-label"><x-icon name="globe" /> {{ __('Satellite now') }}</div>
            <div class="stat-value" style="font-size: 22px">{{ $status['satellites'] ? implode(', ', $status['satellites']) : '–' }}</div>
            <div class="stat-meta">
                @if($status['single_cable'] === true){{ __('one cable: one satellite at a time') }}@elseif($status['single_cable'] === false){{ __('each tuner has its own cable') }}@else–@endif
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><div><h2>{{ __('Latest problems') }}</h2><p>{{ __('Streams the relay refused, and streams where the receiver sent no video.') }}</p></div></div>
        @if(!$status['has_events'] && $status['online'])
            <div class="empty" style="padding: 28px 20px"><p>{{ __('This relay is too old to report problems. Copy the new relay program to the receiver, see the documentation.') }}</p></div>
        @elseif($status['events'] === [])
            <div class="empty" style="padding: 28px 20px"><p>{{ __('No problems since the relay started.') }}</p></div>
        @else
            <ul class="list">
                @foreach($status['events'] as $event)
                    <li>
                        <div>
                            <strong>{{ $event['channel'] }}</strong>
                            <span class="badge badge-warning">{{ $event['kind'] === 'no_video' ? __('No video') : __('Refused') }}</span>
                            <div class="muted">
                                @if($event['kind'] === 'no_video'){{ __('The receiver accepted the stream but sent nothing: its tuner could not lock (busy on another satellite, no signal, or encrypted).') }}
                                @elseif($event['reason'] === 'All tuners are busy'){{ __('Both tuners were busy with other frequencies.') }}
                                @elseif(str_contains($event['reason'], 'another satellite')){{ __('The receiver was on another satellite (one cable).') }}
                                @else{{ $event['reason'] }}@endif
                            </div>
                        </div>
                        <span class="muted" title="{{ \Illuminate\Support\Carbon::createFromTimestamp($event['when']) }}">{{ \Illuminate\Support\Carbon::createFromTimestamp($event['when'])->diffForHumans() }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="card">
        <div class="card-header"><div><h2>{{ __('TV guide from the receiver') }}</h2><p>{{ __('The programmes the receiver itself has collected, for its channels: often the only guide for satellite channels. It is imported every night with the other guides.') }}</p></div></div>
        <div class="card-body stack" style="gap: 12px">
            @if($status['version'] && version_compare($status['version'], '1.3', '<'))
                <div class="alert alert-warning" style="margin: 0"><x-icon name="alert" /><div class="alert-body">{{ __('This needs relay 1.3 or newer on the receiver (it has :version). Copy the new relay file to the receiver and restart it.', ['version' => $status['version']]) }}</div></div>
            @endif
            @if($guide)
                <p>
                    {{ __('Last import: :status', ['status' => $guide->last_synced_at ? $guide->last_synced_at->diffForHumans() : __('not yet')]) }} ·
                    {{ trans_choice(':count channel|:count channels', $guide->channel_count ?? 0, ['count' => number_format($guide->channel_count ?? 0)]) }} ·
                    {{ trans_choice(':count programme|:count programmes', $guide->programme_count ?? 0, ['count' => number_format($guide->programme_count ?? 0)]) }}
                </p>
                @if($guide->last_error)
                    <div class="alert alert-danger" style="margin: 0"><x-icon name="alert" /><div class="alert-body">{{ $guide->last_error }}</div></div>
                @endif
            @endif
            <p class="help" style="margin: 0">{{ __('Enigma2 only keeps programmes of channels it has tuned. An EPG import plugin, or the nightly EPG refresh in the receiver\'s settings, fills it for all channels.') }}</p>
            <form method="POST" action="{{ route('admin.receiver.guide', $source) }}" class="inline-form">
                @csrf
                <button class="btn btn-secondary"><x-icon name="clock" /> {{ $guide ? __('Import the guide now') : __('Use the receiver\'s TV guide') }}</button>
            </form>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.receiver.tidy', $source) }}" class="card" data-confirm="{{ __('Switch off the chosen channels? You can enable them again on the Channels page.') }}">
        @csrf
        <div class="card-header">
            <div>
                <h2>{{ __('Shorten the channel list') }}</h2>
                <p>{{ __('The stream check cannot test receiver channels (it would take a tuner), so this uses what the channel reference and the TV guide say. Switched-off channels stay off after every sync and can be enabled again.') }}</p>
            </div>
        </div>
        <div class="card-body stack" style="gap: 14px">
            @include('admin.partials.errors')
            <label class="check">
                <input type="checkbox" name="criteria[]" value="radio">
                <div><strong>{{ __('Radio and data services') }} <span class="badge">{{ number_format($counts['radio']) }}</span></strong><span>{{ __('Radio stations and services that are not video. Apps show them as empty TV channels.') }}</span></div>
            </label>
            <label class="check">
                <input type="checkbox" name="criteria[]" value="internal">
                <div><strong>{{ __('Internal stream entries') }} <span class="badge">{{ number_format($counts['internal']) }}</span></strong><span>{{ __('Entries that point at a local stream address on the receiver (for example the Spanish radio and regional feeds) instead of a satellite channel. They only play if the receiver runs that stream service; try one first.') }}</span></div>
            </label>
            <label class="check">
                <input type="checkbox" name="criteria[]" value="no_guide">
                <div><strong>{{ __('Channels without a TV guide') }} <span class="badge">{{ number_format($counts['no_guide']) }}</span></strong><span>{{ __('Channels that match no guide. Many of them still play, so look at the TV guide page first: adding the guides of more countries shrinks this number.') }}</span></div>
            </label>
            <p class="help" style="margin: 0">{{ __(':count of :total channels of this source are enabled now.', ['count' => number_format($counts['enabled']), 'total' => number_format($source->channel_count)]) }}</p>
        </div>
        <div class="card-footer">
            <button class="btn btn-primary"><x-icon name="check" /> {{ __('Switch off the chosen channels') }}</button>
        </div>
    </form>
</div>
@endsection
