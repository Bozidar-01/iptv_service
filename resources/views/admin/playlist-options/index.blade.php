@extends('layouts.admin')

@section('title', __('Playlist options'))

@section('content')
<div class="page-header">
    <div>
        <h1>{{ __('Playlist options') }}</h1>
        <p>{{ __('What viewers see in their apps: channel names, numbers and logos. Apps show changes when they load the list again.') }}</p>
    </div>
</div>

<div class="stack">
    <form method="POST" action="{{ route('admin.playlist-options.announcement') }}" class="card">
        @csrf
        <div class="card-header">
            <div><h2>{{ __('Message to viewers') }}</h2><p>{{ __('Shown by TiviMate, IPTV Smarters and other Xtream apps after the login, and at the top of every viewer\'s own page. For example: "Maintenance tonight 02:00–03:00" or "New sports channels added".') }}</p></div>
            @if($announcementShown)<span class="badge badge-success badge-dot">{{ __('Shown now') }}</span>@endif
        </div>
        <div class="card-body form-grid">
            <div class="field full">
                <label class="label" for="announcement-text">{{ __('Message') }} <span class="optional">{{ __('(empty = none)') }}</span></label>
                <textarea class="input" id="announcement-text" name="text" rows="2" maxlength="{{ \App\Services\AnnouncementService::MAX_LENGTH }}">{{ old('text', $announcement['text']) }}</textarea>
            </div>
            <div class="field">
                <label class="label" for="announcement-until">{{ __('Show until') }} <span class="optional">{{ __('(optional)') }}</span></label>
                <input class="input" id="announcement-until" name="until" type="date" value="{{ old('until', $announcement['until']) }}">
                <span class="help">{{ __('The last day it is shown. Empty = until you remove it.') }}</span>
            </div>
        </div>
        <div class="card-footer"><button class="btn btn-primary">{{ __('Save message') }}</button></div>
    </form>

    <form method="POST" action="{{ route('admin.playlist-options.update') }}" class="card">
        @csrf
        <div class="card-header"><div><h2>{{ __('Channel names') }}</h2></div></div>
        <div class="card-body stack" style="gap: 12px">
            <input type="hidden" name="quality_in_names" value="0">
            <label class="check">
                <input type="checkbox" name="quality_in_names" value="1" @checked($settings['quality_in_names'])>
                <div><strong>{{ __('Show the picture quality in channel names') }}</strong><span>{{ __('"Rai 1" becomes "Rai 1 FHD": 4K, FHD, HD or SD, from what the stream check measured, else from the name. Tags already in the name are replaced.') }}</span></div>
            </label>
            <input type="hidden" name="hide_worse_copies" value="0">
            <label class="check">
                <input type="checkbox" name="hide_worse_copies" value="1" @checked($settings['hide_worse_copies'])>
                <div><strong>{{ __('List only the better copy of a channel') }}</strong><span>{{ __('When the same channel (same guide ID) is there twice, viewers only see the one with the better picture that works. It must be in every bouquet the other one is in, so nobody loses the channel. The other copy still plays as a backup.') }}</span></div>
            </label>
            @if($settings['hide_worse_copies'])
                <p class="help" style="margin: 0">{{ trans_choice(':count channel is not listed because a better copy is.|:count channels are not listed because a better copy is.', $counts['better'], ['count' => number_format($counts['better'])]) }}</p>
            @endif
        </div>
        <div class="card-footer"><button type="submit" class="btn btn-primary">{{ __('Save') }}</button></div>
    </form>

    <div class="card">
        <div class="card-header">
            <div><h2>{{ __('Channel numbers') }}</h2><p>{{ __('Every channel has a fixed number that apps show, so viewers can type it on the remote. New channels get the next free numbers; numbers never change by themselves.') }}</p></div>
        </div>
        <div class="card-body stack" style="gap: 12px">
            <p>{{ __(':numbered of :total channels have a number.', ['numbered' => number_format($counts['numbered']), 'total' => number_format($counts['channels'])]) }}</p>
            <form method="POST" action="{{ route('admin.playlist-options.renumber') }}" class="inline-form" data-confirm="{{ __('Number all channels again, in playlist order (category, then position)? Viewers who know a number by heart will find another channel there.') }}">
                @csrf
                <button class="btn btn-secondary"><x-icon name="refresh" /> {{ __('Number all channels again') }}</button>
            </form>
            <p class="help" style="margin: 0">{{ __('To give one channel another number, edit the channel.') }}</p>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div><h2>{{ __('Logos and guide IDs') }}</h2><p>{{ __('Channels without a logo or guide ID get them from the free iptv-org channel database (about 30,000 TV channels), matched by name and country. The source\'s own logo and guide ID always win.') }}</p></div>
        </div>
        <div class="card-body stack" style="gap: 12px">
            <dl class="dl">
                <dt>{{ __('Database downloaded') }}</dt>
                <dd>{{ $catalog['fetched_at'] ? \Illuminate\Support\Carbon::createFromTimestamp($catalog['fetched_at'])->translatedFormat('j M Y H:i') : __('Not yet') }}</dd>
                <dt>{{ __('Logos found') }}</dt>
                <dd>{{ number_format($counts['auto_logos']) }}</dd>
                <dt>{{ __('Guide IDs found') }}</dt>
                <dd>{{ number_format($counts['auto_ids']) }}</dd>
            </dl>
            @if($catalog['error'])
                <div class="alert alert-danger" style="margin: 0"><x-icon name="alert" /><div class="alert-body">{{ __('The last download failed: :error', ['error' => $catalog['error']]) }}</div></div>
            @endif
            <form method="POST" action="{{ route('admin.playlist-options.fill-info') }}" class="inline-form">
                @csrf
                <button class="btn btn-secondary"><x-icon name="refresh" /> {{ __('Look up now') }}</button>
            </form>
            <p class="help" style="margin: 0">{{ __('Runs by itself every week and after every import.') }}</p>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><div><h2>{{ __('Catch-up') }}</h2><p>{{ __('Where a provider keeps an archive, viewers can watch programmes from the past days in apps that support it (TiviMate, Smarters, OTT Navigator, Kodi). NeboTV records nothing itself.') }}</p></div></div>
        <div class="card-body">
            <p>{{ trans_choice(':count channel has catch-up.|:count channels have catch-up.', $counts['catchup'], ['count' => number_format($counts['catchup'])]) }}</p>
        </div>
    </div>
</div>
@endsection
