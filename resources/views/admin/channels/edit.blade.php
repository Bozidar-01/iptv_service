@extends('layouts.admin')

@section('title', __('Edit channel'))
@section('container', 'container-narrow')

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route('admin.channels.index') }}" class="breadcrumb"><x-icon name="arrow-left" /> {{ __('Channels') }}</a>
        <h1>{{ $channel->name }}</h1>
        <p>{{ trans_choice('{0} In no bouquets.|{1} In 1 bouquet.|[2,*] In :count bouquets.', $channel->bouquets()->count()) }}</p>
    </div>
</div>

<div class="card" style="margin-bottom: 16px">
    <div class="card-header"><div><h2>{{ __('How well it works') }}</h2><p>{{ __('From the stream checks of the last :days days and from what viewers ran into.', ['days' => \App\Services\ChannelHealthService::DAYS]) }}</p></div></div>
    <div class="card-body stack" style="gap: 12px">
        <dl class="dl">
            <dt>{{ __('Reliability') }}</dt>
            <dd>
                @if($health['ok'] + $health['failed'] > 0)
                    {{ __(':percent% of :count checks worked', ['percent' => $channel->reliability ?? 0, 'count' => number_format($health['ok'] + $health['failed'])]) }}
                @else
                    <span class="faint">{{ __('Not checked yet') }}</span>
                @endif
            </dd>
            <dt>{{ __('Speed') }}</dt>
            <dd>
                @if($channel->stream_speed === null)
                    <span class="faint">{{ __('Not measured: only for channels people watched in the last week, or with alerts on') }}</span>
                @elseif($channel->stream_speed === 0)
                    <span class="badge badge-warning badge-dot">{{ __('Slow') }}</span> {{ __('The video did not arrive at the last check.') }}
                @elseif(\App\Services\ChannelFailoverService::isSlow($channel->stream_speed))
                    <span class="badge badge-warning badge-dot">{{ __('Slow') }}</span> {{ __('The video arrives at :percent% of the speed it plays: viewers wait for it.', ['percent' => $channel->stream_speed]) }}
                @else
                    {{ __('The video arrives :times times as fast as it plays.', ['times' => number_format($channel->stream_speed / 100, 1)]) }}
                @endif
            </dd>
            <dt>{{ __('Picture') }}</dt>
            <dd>{{ \App\Services\PlaylistOptionsService::label($channel) ?? __('Unknown') }}@if($channel->quality_height) · {{ $channel->quality_height }}p @endif @if($channel->quality_kbps) · {{ number_format($channel->quality_kbps / 1000, 1) }} Mbit/s @endif</dd>
            <dt>{{ __('Viewers with trouble') }}</dt>
            <dd>{{ $health['viewer_trouble'] ? trans_choice(':count time in the last week|:count times in the last week', $health['viewer_trouble'], ['count' => $health['viewer_trouble']]) : __('None') }}</dd>
        </dl>
        <div>
            <div class="label" style="margin-bottom: 6px">{{ __('Backups, best first') }}</div>
            @forelse($ranked as $copy)
                <div class="cell-flex" style="gap: 8px">
                    <span class="truncate">{{ $loop->iteration }}. <a href="{{ route('admin.channels.edit', $copy) }}">{{ $copy->name }}</a> · {{ $copy->source->name ?? __('Manual') }}</span>
                    <span class="muted">{{ $copy->reliability !== null ? $copy->reliability.'%' : '' }} {{ \App\Services\PlaylistOptionsService::label($copy) }}{{ \App\Services\ChannelFailoverService::isSlow($copy->stream_speed) ? ' · '.__('Slow') : '' }}</span>
                </div>
            @empty
                <p class="muted" style="margin: 0">{{ __('No working copy of this channel (same guide ID, or a chosen backup).') }}</p>
            @endforelse
        </div>
    </div>
</div>

<form method="POST" action="{{ route('admin.channels.update', $channel) }}">
    @csrf
    @method('PUT')
    @include('admin.channels._form', ['submit' => __('Save changes')])
</form>
@endsection
