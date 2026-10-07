@extends('layouts.admin')

@section('title', $user->username)

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route('admin.users.index') }}" class="breadcrumb"><x-icon name="arrow-left" /> Users</a>
        <h1>{{ $user->username }}</h1>
        <p>
            @include('admin.partials.user-status', ['user' => $user])
            @if($user->isAdmin()) <span class="badge badge-primary">Admin</span> @endif
        </p>
    </div>
    <div class="page-actions">
        <form method="POST" action="{{ route('admin.users.toggle', $user) }}" class="inline-form">
            @csrf
            <button class="btn btn-secondary"><x-icon name="power" /> {{ $user->status === 'active' ? 'Disable' : 'Enable' }}</button>
        </form>
        <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-primary"><x-icon name="edit" /> Edit</a>
    </div>
</div>

<div class="grid-main">
    <div class="stack">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2>Playlist link</h2>
                    <p>Paste this into the IPTV app on the TV.</p>
                </div>
                <form method="POST" action="{{ route('admin.users.playlist-token', $user) }}" class="inline-form"
                      data-confirm="Generate a new link? The current link stops working and the TV app has to be updated.">
                    @csrf
                    <button class="btn btn-ghost btn-sm"><x-icon name="refresh" /> New link</button>
                </form>
            </div>
            <div class="card-body stack" style="gap: 16px">
                @if($isLocalHost)
                    <div class="alert alert-warning" style="margin: 0">
                        <x-icon name="alert" />
                        <div class="alert-body">
                            This link uses <code>{{ request()->getHost() }}</code>, which your TV can't reach. Open this panel using your
                            computer's network address (e.g. <code>http://192.168.1.20:8000</code>) and the link updates automatically.
                            Docker already accepts connections from your network; with <code>php artisan serve</code>, add <code>--host=0.0.0.0</code>.
                        </div>
                    </div>
                @endif

                @if($user->status !== 'active' || $user->isExpired())
                    <div class="alert alert-danger" style="margin: 0">
                        <x-icon name="alert" />
                        <div class="alert-body">This account is {{ $user->status !== 'active' ? 'disabled' : 'expired' }}, so the playlist won't load.</div>
                    </div>
                @elseif($channelCount === 0)
                    <div class="alert alert-warning" style="margin: 0">
                        <x-icon name="alert" />
                        <div class="alert-body">The playlist is empty. <a href="{{ route('admin.users.edit', $user) }}">Give this user a bouquet</a> with enabled channels.</div>
                    </div>
                @endif

                <div class="copy-field">
                    <input class="input" id="playlist-url" value="{{ $playlistUrl }}" readonly onclick="this.select()">
                    <button type="button" class="btn btn-primary" data-copy="#playlist-url"><x-icon name="copy" /> <span data-copy-label>Copy</span></button>
                </div>

                <div>
                    <div class="label" style="margin-bottom: 6px">Set up on a TV</div>
                    <ol class="steps">
                        <li>Install an IPTV player: <strong>TiviMate</strong>, <strong>IPTV Smarters</strong>, <strong>OTT Navigator</strong> (Android TV / Fire TV), or <strong>VLC</strong>.</li>
                        <li>Choose <strong>Add playlist → M3U playlist / URL</strong>.</li>
                        <li>Paste the link above and save. {{ number_format($channelCount) }} channels will load.</li>
                    </ol>
                    <p class="help" style="margin-top: 8px">
                        Apps that ask for a username and password instead can use
                        <code>{{ url('/m3u') }}?username={{ $user->username }}&password=…</code>
                    </p>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Recent activity</h2>
                <a href="{{ route('admin.logs.index', ['user' => $user->id]) }}" class="btn btn-ghost btn-sm">View all</a>
            </div>
            @if($recentLogs->isEmpty())
                <div class="empty" style="padding: 28px 20px"><p>Nothing watched yet.</p></div>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Channel</th><th class="hide-sm">IP address</th><th class="right">When</th></tr></thead>
                        <tbody>
                        @foreach($recentLogs as $log)
                            <tr>
                                <td class="cell-main truncate">{{ $log->channel->name ?? 'Deleted channel' }}</td>
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
        <div class="card-header"><h2>Subscription</h2></div>
        <div class="card-body">
            <dl class="dl">
                <dt>Expires</dt>
                <dd>{{ $user->expiration_date ? $user->expiration_date->format('M j, Y') . ' (' . $user->expiration_date->diffForHumans() . ')' : 'Never' }}</dd>
                <dt>Streams</dt>
                <dd>{{ $user->active_sessions_count }} active now · max {{ $user->max_connections }}</dd>
                <dt>Channels</dt>
                <dd>{{ number_format($channelCount) }}</dd>
                <dt>Bouquets</dt>
                <dd>
                    @forelse($user->bouquets as $bouquet)
                        <span class="badge badge-primary">{{ $bouquet->name }}</span>
                    @empty
                        <span class="faint">None</span>
                    @endforelse
                </dd>
                <dt>Created</dt>
                <dd>{{ $user->created_at?->format('M j, Y') }}</dd>
            </dl>
        </div>
    </div>
</div>
@endsection
