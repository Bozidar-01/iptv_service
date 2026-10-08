<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ \App\Support\Theme::current() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ $user->username }} · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}?v={{ filemtime(public_path('css/admin.css')) }}">
    @include('admin.partials.favicons')
</head>
<body>
@php
    $expired = $user->expiration_date !== null && $user->expiration_date->isPast();
    $disabled = $user->status !== 'active';
    $endsSoon = !$expired && $canRenew;
@endphp
<div class="auth">
    <div class="auth-card wide">
        <div class="auth-brand">
            <span class="brand-mark"><x-icon name="nebotv" /></span>
            <div>
                <h1><x-brand-name /></h1>
                <p>{{ __('Hello, :name', ['name' => $user->username]) }}</p>
            </div>
        </div>

        @if($sent === 'yes')
            <div class="alert alert-success" role="status"><x-icon name="check-circle" /><div class="alert-body">{{ __('Your request was sent. You will be contacted.') }}</div></div>
        @elseif($sent === 'again')
            <div class="alert alert-info" role="status"><x-icon name="check-circle" /><div class="alert-body">{{ __('You already asked today. Your request is on its way.') }}</div></div>
        @endif

        <div class="card">
            <div class="card-header">
                <h2>{{ __('Your subscription') }}</h2>
                @if($disabled)
                    <span class="badge badge-danger badge-dot">{{ __('Disabled') }}</span>
                @elseif($expired)
                    <span class="badge badge-danger badge-dot">{{ __('Expired') }}</span>
                @else
                    <span class="badge badge-success badge-dot">{{ __('Active') }}</span>
                @endif
            </div>
            <div class="card-body stack" style="gap: 14px">
                <p>
                    @if($user->expiration_date === null)
                        {{ __('No end date') }}
                    @elseif($expired)
                        {{ __('Ended on :date (:relative)', ['date' => $user->expiration_date->translatedFormat('j M Y'), 'relative' => $user->expiration_date->diffForHumans()]) }}
                    @else
                        {{ __('Valid until :date (:relative)', ['date' => $user->expiration_date->translatedFormat('j M Y'), 'relative' => $user->expiration_date->diffForHumans()]) }}
                    @endif
                </p>

                @if($disabled)
                    <div class="alert alert-danger" style="margin: 0"><x-icon name="alert" /><div class="alert-body">{{ __('This account is switched off. Contact the person who gave you access.') }}</div></div>
                @elseif($expired)
                    <div class="alert alert-danger" style="margin: 0"><x-icon name="alert" /><div class="alert-body">{{ __('Your subscription has ended, so the channels will not load.') }}</div></div>
                @elseif($endsSoon)
                    <div class="alert alert-warning" style="margin: 0"><x-icon name="alert" /><div class="alert-body">{{ __('Your subscription is active, but it ends soon.') }}</div></div>
                @endif

                @if($canRenew && !$disabled)
                    <form method="POST" action="{{ route('me.renew', ['token' => $token, 'lang' => $lang]) }}">
                        <button type="submit" class="btn btn-primary"><x-icon name="refresh" /> {{ __('Ask to renew') }}</button>
                    </form>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>{{ __('Your playlist link') }}</h2></div>
            <div class="card-body stack" style="gap: 14px">
                <div class="copy-field">
                    <input class="input" id="playlist-url" value="{{ $playlistUrl }}" readonly onclick="this.select()">
                    <button type="button" class="btn btn-primary" data-copy="#playlist-url"><x-icon name="copy" /> <span data-copy-label>{{ __('Copy') }}</span></button>
                </div>
                <p class="help" style="margin: 0">{{ __('Paste it into your IPTV app as an M3U link. Do not share it: it is your personal link.') }}</p>

                <div>
                    <div class="label" style="margin-bottom: 6px">{{ __('Set up on a TV') }}</div>
                    <ol class="steps">
                        <li>{!! __('In the app choose :option.', ['option' => '<strong>'.e(__('Add playlist → M3U URL')).'</strong>']) !!}</li>
                        <li>{{ __('Paste the link above.') }}</li>
                    </ol>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>{{ __('Or log in with a username and password') }}</h2></div>
            <div class="card-body">
                <dl class="dl">
                    <dt>{{ __('Server') }}</dt>
                    <dd class="mono">{{ $serverUrl }}</dd>
                    <dt>{{ __('Username') }}</dt>
                    <dd class="mono">{{ $user->username }}</dd>
                </dl>
                <p class="help" style="margin: 8px 0 0">{{ __('The password is the one you were given.') }}</p>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>{{ __('What you can watch') }}</h2></div>
            <div class="card-body stack" style="gap: 10px">
                <p>{{ trans_choice(':count channel in :bouquets|:count channels in :bouquets', $channelCount, ['count' => number_format($channelCount), 'bouquets' => $user->bouquets->pluck('name')->join(', ') ?: '–']) }}</p>
                <p class="muted">{{ __('Streams at the same time: :active of :max in use', ['active' => $user->active_sessions_count, 'max' => $user->max_connections]) }}</p>
            </div>
        </div>

        <nav class="me-lang" aria-label="{{ __('Language') }}">
            @foreach(config('app.locales') as $code => $locale)
                <a href="{{ route('me', ['token' => $token, 'lang' => $code]) }}" lang="{{ $code }}" @if($code === $lang) aria-current="true" @endif>{{ $locale['name'] }}</a>
            @endforeach
        </nav>
    </div>
</div>

<script>
    // Copy button (the panel's own script is not loaded on this page)
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-copy]');
        if (!button) return;
        const input = document.querySelector(button.dataset.copy);
        try { await navigator.clipboard.writeText(input.value); } catch (e) { input.select(); document.execCommand('copy'); }
        const label = button.querySelector('[data-copy-label]');
        if (label) {
            const original = label.textContent;
            label.textContent = @json(__('Copied'));
            setTimeout(() => label.textContent = original, 1500);
        }
    });
</script>
</body>
</html>
