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
            @if($user->isReseller()) <span class="badge badge-info">{{ __('Reseller') }}</span> @endif
        </p>
    </div>
    <div class="page-actions">
        <form method="POST" action="{{ route('admin.users.toggle', $user) }}" class="inline-form">
            @csrf
            <button class="btn btn-secondary"><x-icon name="power" /> {{ $user->status === 'active' ? __('Disable') : __('Enable') }}</button>
        </form>
        @if($user->role === 'user')
            <a href="{{ route('admin.users.channels', $user) }}" class="btn btn-secondary"><x-icon name="tv" /> {{ __('Choose channels') }}</a>
        @endif
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

                @if($user->role === 'user')
                <div>
                    <div class="label" style="margin-bottom: 6px">{{ __('Page for this user') }}</div>
                    <div class="copy-field">
                        <input class="input" id="customer-url" value="{{ route('me', ['token' => $user->playlist_token]) }}" readonly onclick="this.select()">
                        <button type="button" class="btn btn-secondary" data-copy="#customer-url"><x-icon name="copy" /> <span data-copy-label>{{ __('Copy') }}</span></button>
                    </div>
                    <p class="help" style="margin: 6px 0 0">{{ __('Send this to the user: it shows their expiry, the playlist link and a button to ask for a renewal. It stops working when you make a new playlist link.') }}</p>
                </div>
                @endif

                <div>
                    <div class="label" style="margin-bottom: 6px">{{ __('Set up on a TV') }}</div>
                    <ol class="steps">
                        <li>{!! __('Install an IPTV player: :apps (Android TV / Fire TV), or :vlc.', ['apps' => '<strong>TiviMate</strong>, <strong>IPTV Smarters</strong>, <strong>OTT Navigator</strong>', 'vlc' => '<strong>VLC</strong>']) !!}</li>
                        <li>{!! __('Choose :option.', ['option' => '<strong>'.e(__('Add playlist → M3U playlist / URL')).'</strong>']) !!}</li>
                        <li>{{ trans_choice('Paste the link above and save. :count channel will load.|Paste the link above and save. :count channels will load.', $channelCount, ['count' => number_format($channelCount)]) }}</li>
                    </ol>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div>
                    <h2>{{ __('Log in with username and password') }}</h2>
                    <p>{{ __('For apps that ask for a server, username and password (Xtream Codes login).') }}</p>
                </div>
            </div>
            <div class="card-body stack" style="gap: 16px">
                <div class="login-fields">
                    <label class="label" for="xtream-url">{{ __('Server URL') }}</label>
                    <div class="copy-field">
                        <input class="input mono" id="xtream-url" value="{{ $server['url'] }}" readonly onclick="this.select()">
                        <button type="button" class="btn btn-secondary" data-copy="#xtream-url"><x-icon name="copy" /> <span data-copy-label>{{ __('Copy') }}</span></button>
                    </div>

                    <label class="label" for="xtream-username">{{ __('Username') }}</label>
                    <div class="copy-field">
                        <input class="input mono" id="xtream-username" value="{{ $user->username }}" readonly onclick="this.select()">
                        <button type="button" class="btn btn-secondary" data-copy="#xtream-username"><x-icon name="copy" /> <span data-copy-label>{{ __('Copy') }}</span></button>
                    </div>

                    <label class="label" for="xtream-password">{{ __('Password') }}</label>
                    @if($user->password_plain)
                        <div>
                            <div class="copy-field">
                                <input class="input mono" id="xtream-password" type="password" value="{{ $user->password_plain }}" readonly onclick="this.select()">
                                <button type="button" class="btn btn-secondary" data-reveal="#xtream-password"><x-icon name="eye" /> <span data-reveal-label>{{ __('Show current password') }}</span></button>
                                <button type="button" class="btn btn-secondary" data-copy="#xtream-password"><x-icon name="copy" /> <span data-copy-label>{{ __('Copy') }}</span></button>
                            </div>
                            <p class="help" style="margin: 6px 0 0"><a href="{{ route('admin.users.edit', $user) }}">{{ __('Change password') }}</a></p>
                        </div>
                    @else
                        <div class="login-password">
                            {{ __('The current password is not stored for this user. Set a new one to see it here.') }}
                            <a href="{{ route('admin.users.edit', $user) }}">{{ __('Change password') }}</a>
                        </div>
                    @endif
                </div>

                <p class="help" style="margin: 0">
                    {!! __('Apps that ask for host and port separately: host :host, port :port.', [
                        'host' => '<code>'.e($server['host']).'</code>',
                        'port' => '<code>'.e($server['port']).'</code>',
                    ]) !!}
                </p>

                <div>
                    <div class="label" style="margin-bottom: 6px">{{ __('Set up on a TV') }}</div>
                    <ol class="steps">
                        <li>{!! __('In the app choose :option.', ['option' => '<strong>'.e(__('Add user → Login with Xtream Codes API')).'</strong>']) !!}</li>
                        <li>{{ __('Enter any name, then the server URL, username and password above.') }}</li>
                        <li>{{ __('Live TV loads with the same channels, categories and connection limit as the playlist link.') }}</li>
                    </ol>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div>
                    <h2>{{ __('Message for the user') }}</h2>
                    <p>{{ __('Everything the user needs to log in. Copy it and paste it into WhatsApp, Viber or an email.') }}</p>
                </div>
                <button type="button" class="btn btn-primary btn-sm" data-copy="#login-message"><x-icon name="copy" /> <span data-copy-label>{{ __('Copy message') }}</span></button>
            </div>
            <div class="card-body">
                <textarea class="textarea mono" id="login-message" rows="{{ substr_count($loginMessage, "\n") + 2 }}" readonly onclick="this.select()" aria-label="{{ __('Message for the user') }}">{{ $loginMessage }}</textarea>
                @unless($user->password_plain)
                    <p class="help" style="margin: 6px 0 0">{{ __('The password is not in the message because it is not stored for this user. Set a new one to include it.') }}</p>
                @endunless
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
        <div class="card-header">
            <div>
                <h2>{{ __('Devices watching now') }}</h2>
                <p>{{ __('A player that plays straight from the provider keeps its current stream until it reconnects. Streams played through this server stop within seconds.') }}</p>
            </div>
            @if($devices->count() > 1)
                <form method="POST" action="{{ route('admin.users.sessions.disconnect-all', $user) }}" data-confirm="{{ __('Disconnect all devices of this user? They can not start a stream for :minutes minutes.', ['minutes' => \App\Services\StreamService::DEVICE_BLOCK_MINUTES]) }}">
                    @csrf
                    <button type="submit" class="btn btn-danger-ghost btn-sm"><x-icon name="power" /> {{ __('Disconnect all') }}</button>
                </form>
            @endif
        </div>
        @if($devices->isEmpty())
            <div class="empty" style="padding: 28px 20px"><p>{{ __('Nobody is watching right now.') }}</p></div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Channel') }}</th><th class="hide-sm">{{ __('IP address') }}</th><th class="hide-sm">{{ __('Player') }}</th><th class="right">{{ __('Since') }}</th><th class="shrink"></th></tr></thead>
                    <tbody>
                    @foreach($devices as $device)
                        <tr>
                            <td class="cell-main truncate">{{ $device->channel->name ?? __('Deleted channel') }}</td>
                            <td class="hide-sm mono muted">{{ $device->ip }}</td>
                            <td class="hide-sm muted truncate" style="max-width: 200px" title="{{ $device->user_agent }}">{{ $device->user_agent }}</td>
                            <td class="right muted" title="{{ $device->started_at }}">{{ $device->started_at->diffForHumans() }}</td>
                            <td>
                                <form method="POST" action="{{ route('admin.users.sessions.disconnect', [$user, $device]) }}" data-confirm="{{ __('Disconnect this device? It can not start a stream for :minutes minutes.', ['minutes' => \App\Services\StreamService::DEVICE_BLOCK_MINUTES]) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-danger-ghost btn-sm">{{ __('Disconnect') }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if($refused->isNotEmpty())
        <div class="card">
            <div class="card-header">
                <div>
                    <h2>{{ __('Refused plays') }}</h2>
                    <p>{{ __('Why this user\'s latest plays were refused.') }}</p>
                </div>
                <a href="{{ route('admin.logs.refused', ['user' => $user->id]) }}" class="btn btn-ghost btn-sm">{{ __('View all') }}</a>
            </div>
            <ul class="list">
                @foreach($refused as $row)
                    <li>
                        <div>
                            <strong>{{ $row->channel->name ?? __('Deleted channel') }}</strong> <span class="badge badge-warning">{{ $row->label() }}</span>
                            <div class="muted">{{ $row->hint() }}</div>
                        </div>
                        <span class="muted" title="{{ $row->created_at }}">{{ $row->created_at->diffForHumans() }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($addresses)
        <div class="card">
            <div class="card-header">
                <div>
                    <h2>{{ __('Where this user watches from') }}</h2>
                    <p>{{ __('Addresses used in the last 7 days. Many different ones in a day can mean the login is shared.') }}</p>
                </div>
                @if(config('iptv.sharing_ips') >= 2 && $recentAddressCount >= config('iptv.sharing_ips'))
                    <span class="badge badge-warning badge-dot">{{ __(':count addresses in :hours hours', ['count' => $recentAddressCount, 'hours' => config('iptv.sharing_hours')]) }}</span>
                @endif
            </div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('IP address') }}</th><th class="right">{{ __('Plays') }}</th><th class="right">{{ __('Last used') }}</th></tr></thead>
                    <tbody>
                    @foreach($addresses as $address)
                        <tr>
                            <td class="mono">{{ $address['ip'] }}</td>
                            <td class="right">{{ number_format($address['plays']) }}</td>
                            <td class="right muted" title="{{ $address['last'] }}">{{ \Illuminate\Support\Carbon::parse($address['last'])->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if($user->isReseller() && Auth::user()->isAdmin())
        <div class="card">
            <div class="card-header">
                <div>
                    <h2>{{ __('Credits') }}</h2>
                    <p>{{ __('1 credit = 1 month of subscription for one user. Giving credits switches the limit on.') }}</p>
                </div>
                <a href="{{ route('admin.credits.index', ['reseller' => $user->id]) }}" class="btn btn-ghost btn-sm">{{ __('History') }}</a>
            </div>
            <div class="card-body stack" style="gap: 16px">
                <div class="stat-value" style="margin: 0">{{ $user->usesCredits() ? number_format($user->credits) : __('Unlimited') }}</div>

                <form method="POST" action="{{ route('admin.users.credits.store', $user) }}" class="form-grid">
                    @csrf
                    <div class="field">
                        <label class="label" for="credit-amount">{{ __('Give or take back') }}</label>
                        <input class="input" id="credit-amount" name="amount" type="number" step="1" min="-1000000" max="1000000" placeholder="10" required>
                        <span class="help">{{ __('A positive number gives credits, a negative one takes them back.') }}</span>
                    </div>
                    <div class="field">
                        <label class="label" for="credit-note">{{ __('Note') }} <span class="optional">{{ __('(optional)') }}</span></label>
                        <input class="input" id="credit-note" name="note" maxlength="255">
                    </div>
                    <div class="field full"><div><button class="btn btn-primary" type="submit"><x-icon name="package" /> {{ __('Save') }}</button></div></div>
                </form>

                @if($user->usesCredits())
                    <form method="POST" action="{{ route('admin.users.credits.unlimited', $user) }}" data-confirm="{{ __('Remove the credit limit of this reseller? They can then give any number of subscriptions.') }}">
                        @csrf
                        <button class="btn btn-danger-ghost btn-sm" type="submit">{{ __('Make unlimited') }}</button>
                    </form>
                @endif
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-header"><h2>{{ __('Subscription') }}</h2></div>
        <div class="card-body">
            <dl class="dl">
                <dt>{{ __('Expires') }}</dt>
                <dd>{{ $user->expiration_date ? $user->expiration_date->translatedFormat('M j, Y') . ' (' . $user->expiration_date->diffForHumans() . ')' : __('Never') }}</dd>
                @if($user->role === 'user' && $user->expiration_date)
                    <dt>{{ __('Renew') }}</dt>
                    <dd>
                        @include('admin.partials.renew-form', ['user' => $user])
                    </dd>
                @endif
                <dt>{{ __('Streams') }}</dt>
                <dd>{{ __(':count active now · max :max', ['count' => $user->active_sessions_count, 'max' => $user->max_connections]) }}</dd>
                <dt>{{ __('Channels') }}</dt>
                <dd>{{ number_format($channelCount) }}@if($user->role === 'user') · <a href="{{ route('admin.users.channels', $user) }}">{{ __('Hide some') }}</a>@endif</dd>
                <dt>{{ __('Bouquets') }}</dt>
                <dd>
                    @forelse($user->bouquets as $bouquet)
                        <span class="badge badge-primary">{{ $bouquet->name }}</span>
                    @empty
                        <span class="faint">{{ __('None') }}</span>
                    @endforelse
                </dd>
                @if($user->owner && Auth::user()->isAdmin())
                    <dt>{{ __('Reseller') }}</dt>
                    <dd><a href="{{ route('admin.users.show', $user->owner) }}">{{ $user->owner->username }}</a></dd>
                @endif
                @if($user->isReseller())
                    <dt>{{ __('Users') }}</dt>
                    <dd>
                        {{ $user->max_customers !== null ? __(':count of :max', ['count' => $user->customers_count, 'max' => $user->max_customers]) : $user->customers_count }}
                        @if($user->customers_count)
                            · <a href="{{ route('admin.users.index', ['reseller' => $user->id]) }}">{{ __('View all') }}</a>
                        @endif
                    </dd>
                    <dt>{{ __('Most streams per user') }}</dt>
                    <dd>{{ $user->max_customer_connections ?? __('No limit') }}</dd>
                @endif
                <dt>{{ __('Email') }}</dt>
                <dd>{{ $user->email ?: '–' }}</dd>
                <dt>{{ __('Created') }}</dt>
                <dd>{{ $user->created_at?->translatedFormat('M j, Y') }}</dd>
            </dl>
        </div>
    </div>
</div>
@endsection
