@extends('layouts.admin')

@section('title', $user->username)

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route('admin.users.index') }}" class="breadcrumb"><x-icon name="arrow-left" /> {{ __('Users') }}</a>
        <h1>{{ $user->username }}</h1>
        <p>
            @include('admin.partials.user-status', ['user' => $user])
            @if($user->isAdmin()) <span class="badge badge-primary">{{ __('Admin') }}</span> @endif
        </p>
    </div>
    <div class="page-actions">
        <form method="POST" action="{{ route('admin.users.toggle', $user) }}" class="inline-form">
            @csrf
            <button class="btn btn-secondary"><x-icon name="power" /> {{ $user->status === 'active' ? __('Disable') : __('Enable') }}</button>
        </form>
        <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-primary"><x-icon name="edit" /> {{ __('Edit') }}</a>
    </div>
</div>

<div class="grid-main">
    <div class="stack">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2>{{ __('Playlist link') }}</h2>
                    <p>{{ __('Paste this into the IPTV app on the TV.') }}</p>
                </div>
                <form method="POST" action="{{ route('admin.users.playlist-token', $user) }}" class="inline-form"
                      data-confirm="{{ __('Generate a new link? The current link stops working and the TV app has to be updated.') }}">
                    @csrf
                    <button class="btn btn-ghost btn-sm"><x-icon name="refresh" /> {{ __('New link') }}</button>
                </form>
            </div>
            <div class="card-body stack" style="gap: 16px">
                @if($isLocalHost)
                    <div class="alert alert-warning" style="margin: 0">
                        <x-icon name="alert" />
                        <div class="alert-body">
                            {!! __("This link uses :host, which your TV can't reach. Open this panel using your computer's network address (e.g. :example) and the link updates automatically. Docker already accepts connections from your network; with :serve, add :flag.", [
                                'host' => '<code>'.e(request()->getHost()).'</code>',
                                'example' => '<code>http://192.168.1.20:8000</code>',
                                'serve' => '<code>php artisan serve</code>',
                                'flag' => '<code>--host=0.0.0.0</code>',
                            ]) !!}
                        </div>
                    </div>
                @endif

                @if($user->status !== 'active' || $user->isExpired())
                    <div class="alert alert-danger" style="margin: 0">
                        <x-icon name="alert" />
                        <div class="alert-body">{{ $user->status !== 'active' ? __("This account is disabled, so the playlist won't load.") : __("This account is expired, so the playlist won't load.") }}</div>
                    </div>
                @elseif($channelCount === 0)
                    <div class="alert alert-warning" style="margin: 0">
                        <x-icon name="alert" />
                        <div class="alert-body">{!! __('The playlist is empty. :link with enabled channels.', ['link' => '<a href="'.e(route('admin.users.edit', $user)).'">'.e(__('Give this user a bouquet')).'</a>']) !!}</div>
                    </div>
                @endif

                <div class="copy-field">
                    <input class="input" id="playlist-url" value="{{ $playlistUrl }}" readonly onclick="this.select()">
                    <button type="button" class="btn btn-primary" data-copy="#playlist-url"><x-icon name="copy" /> <span data-copy-label>{{ __('Copy') }}</span></button>
                </div>

                <div>
                    <div class="label" style="margin-bottom: 6px">{{ __('Set up on a TV') }}</div>
                    <ol class="steps">
                        <li>{!! __('Install an IPTV player: :apps (Android TV / Fire TV), or :vlc.', ['apps' => '<strong>TiviMate</strong>, <strong>IPTV Smarters</strong>, <strong>OTT Navigator</strong>', 'vlc' => '<strong>VLC</strong>']) !!}</li>
                        <li>{!! __('Choose :option.', ['option' => '<strong>'.e(__('Add playlist → M3U playlist / URL')).'</strong>']) !!}</li>
                        <li>{{ trans_choice('Paste the link above and save. :count channel will load.|Paste the link above and save. :count channels will load.', $channelCount, ['count' => number_format($channelCount)]) }}</li>
                    </ol>
                    <p class="help" style="margin-top: 8px">
                        {{ __('Apps that ask for a username and password instead can use') }}
                        <code>{{ url('/m3u') }}?username={{ $user->username }}&password=…</code>
                    </p>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>{{ __('Recent activity') }}</h2>
                <a href="{{ route('admin.logs.index', ['user' => $user->id]) }}" class="btn btn-ghost btn-sm">{{ __('View all') }}</a>
            </div>
            @if($recentLogs->isEmpty())
                <div class="empty" style="padding: 28px 20px"><p>{{ __('Nothing watched yet.') }}</p></div>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>{{ __('Channel') }}</th><th class="hide-sm">{{ __('IP address') }}</th><th class="right">{{ __('When') }}</th></tr></thead>
                        <tbody>
                        @foreach($recentLogs as $log)
                            <tr>
                                <td class="cell-main truncate">{{ $log->channel->name ?? __('Deleted channel') }}</td>
                                <td class="hide-sm mono muted">{{ $log->ip }}</td>
                                <td class="right muted" title="{{ $log->started_at }}">{{ $log->started_at->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>{{ __('Subscription') }}</h2></div>
        <div class="card-body">
            <dl class="dl">
                <dt>{{ __('Expires') }}</dt>
                <dd>{{ $user->expiration_date ? $user->expiration_date->translatedFormat('M j, Y') . ' (' . $user->expiration_date->diffForHumans() . ')' : __('Never') }}</dd>
                <dt>{{ __('Streams') }}</dt>
                <dd>{{ __(':count active now · max :max', ['count' => $user->active_sessions_count, 'max' => $user->max_connections]) }}</dd>
                <dt>{{ __('Channels') }}</dt>
                <dd>{{ number_format($channelCount) }}</dd>
                <dt>{{ __('Bouquets') }}</dt>
                <dd>
                    @forelse($user->bouquets as $bouquet)
                        <span class="badge badge-primary">{{ $bouquet->name }}</span>
                    @empty
                        <span class="faint">{{ __('None') }}</span>
                    @endforelse
                </dd>
                <dt>{{ __('Created') }}</dt>
                <dd>{{ $user->created_at?->translatedFormat('M j, Y') }}</dd>
            </dl>
        </div>
    </div>
</div>
@endsection
