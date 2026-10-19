@extends('layouts.admin')

@section('title', __('Duplicate channels'))

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route('admin.channels.index') }}" class="breadcrumb"><x-icon name="arrow-left" /> {{ __('Channels') }}</a>
        <h1>{{ __('Duplicate channels') }}</h1>
        <p>{{ __('The same channel listed more than once, for example by two sources. Keep the copy that works and looks best; the others are switched off (not deleted, so a sync does not bring them back).') }}</p>
    </div>
    @if($groups->total() > 0)
        <div class="page-actions">
            <form method="POST" action="{{ route('admin.channels.duplicates.apply') }}" class="inline-form"
                  data-confirm="{{ trans_choice('Switch off :count extra channel and keep the suggested copy of each group?|Switch off :count extra channels and keep the suggested copy of each group?', $extra, ['count' => number_format($extra)]) }}">
                @csrf
                <input type="hidden" name="scope" value="all">
                <input type="hidden" name="search" value="{{ $search }}">
                <button class="btn btn-primary"><x-icon name="check" /> {{ __('Keep the suggested copy in all :count groups', ['count' => number_format($groups->total())]) }}</button>
            </form>
        </div>
    @endif
</div>

<div class="card" style="margin-bottom: 20px">
    <form method="GET" action="{{ route('admin.channels.duplicates') }}" class="toolbar">
        <div class="input-group">
            <x-icon name="search" />
            <input class="input" type="search" name="search" value="{{ $search }}" placeholder="{{ __('Search channels…') }}">
        </div>
        @if($search)
            <a href="{{ route('admin.channels.duplicates') }}" class="btn btn-ghost btn-sm"><x-icon name="x" /> {{ __('Clear') }}</a>
        @endif
    </form>
    <div class="card-body">
        <p class="help" style="margin: 0">{{ __('Suggested copy: the one that works (run Check streams first for an up-to-date answer), then not slow, then the most reliable over the last week, then the best quality (4K, Full HD, HD), then not a satellite receiver (it can only serve a few streams), then the one in most bouquets. Channels without a quality tag count as between HD and SD. The Quality column shows the picture size the stream check measured (HLS channels), or a guess from the name. The kept copy is added to the bouquets of the ones switched off.') }}</p>
    </div>
</div>

@if($groups->isEmpty())
    <div class="card">
        <div class="empty">
            <div class="empty-icon"><x-icon name="check-circle" /></div>
            <h3>{{ $search ? __('No duplicates match this search') : __('No duplicates found') }}</h3>
            <p>{{ __('Only enabled channels that are in a bouquet are looked at.') }}</p>
        </div>
    </div>
@else
    <form method="POST" action="{{ route('admin.channels.duplicates.apply') }}">
        @csrf
        <input type="hidden" name="scope" value="page">
        <input type="hidden" name="search" value="{{ $search }}">

        <div class="stack">
            @foreach($groups as $group)
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2>{{ $group['name'] }}</h2>
                            <p>{{ trans_choice(':count copy|:count copies', count($group['channels']), ['count' => count($group['channels'])]) }}</p>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th class="shrink">{{ __('Keep') }}</th><th>{{ __('Channel') }}</th><th>{{ __('Source') }}</th><th>{{ __('Status') }}</th><th class="hide-sm">{{ __('Quality') }}</th><th class="hide-sm right">{{ __('Bouquets') }}</th></tr></thead>
                            <tbody>
                            @foreach($group['channels'] as $channel)
                                <tr>
                                    <td><input type="radio" name="keep[{{ $group['key'] }}]" value="{{ $channel['id'] }}" @checked($channel['id'] === $group['suggested']) aria-label="{{ __('Keep :name', ['name' => $channel['name']]) }}" style="accent-color: var(--primary)"></td>
                                    <td>
                                        <div class="cell-main">{{ $channel['name'] }}@if($channel['id'] === $group['suggested']) <span class="badge badge-success">{{ __('Suggested') }}</span>@endif</div>
                                    </td>
                                    <td>{{ $channel['source'] }}@if($channel['receiver']) <span class="badge badge-info">{{ __('Receiver') }}</span>@endif</td>
                                    <td>
                                        @if($channel['status'] === 'online')
                                            <span class="badge badge-success badge-dot">{{ __('Working') }}</span>
                                        @elseif($channel['status'] === 'offline')
                                            <span class="badge badge-danger badge-dot">{{ __('Not working') }}</span>
                                        @else
                                            <span class="badge badge-dot">{{ __('Not checked') }}</span>
                                        @endif
                                    </td>
                                    <td class="hide-sm">@if($channel['measured'])<span class="badge badge-success" title="{{ __('Measured by the stream check') }}">{{ $channel['measured']['height'] ? $channel['measured']['height'].'p' : '–' }}@if($channel['measured']['kbps']) · {{ number_format($channel['measured']['kbps'] / 1000, 1) }} Mbps @endif</span>@else<span class="muted" title="{{ __('Guessed from the name') }}">{{ [5 => '4K', 4 => 'Full HD', 3 => 'HD', 2 => '–', 1 => 'SD'][$channel['quality']] }}</span>@endif
                                        @if($channel['reliability'] !== null)<div class="cell-sub">{{ __(':percent% working', ['percent' => $channel['reliability']]) }}{{ $channel['slow'] ? ' · '.__('Slow') : '' }}</div>@endif</td>
                                    <td class="hide-sm right">{{ $channel['bouquets'] }}</td>
                                </tr>
                            @endforeach
                                <tr>
                                    <td><input type="radio" name="keep[{{ $group['key'] }}]" value="0" aria-label="{{ __('Leave this group as it is') }}" style="accent-color: var(--primary)"></td>
                                    <td colspan="5" class="muted">{{ __('Leave all as they are') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="card" style="margin-top: 20px">
            <div class="card-footer">
                <button class="btn btn-primary"><x-icon name="check" /> {{ __('Switch off the others on this page') }}</button>
            </div>
        </div>
    </form>

    {{ $groups->links('admin.partials.pagination') }}
@endif
@endsection
