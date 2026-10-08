@extends('layouts.admin')

@section('title', __('Security'))

@section('content')
<div class="page-header">
    <div>
        <h1>{{ __('Security') }}</h1>
        <p>{{ __('Protect the admin panel and see who signed in.') }}</p>
    </div>
</div>

<div class="stack">
    @if(session('recovery_codes'))
        <div class="card">
            <div class="card-header">
                <div>
                    <h2>{{ __('Your recovery codes') }}</h2>
                    <p>{{ __('Keep them somewhere safe, like a password manager. Each works once if you lose your phone. They are not shown again.') }}</p>
                </div>
            </div>
            <div class="card-body">
                <ul class="recovery-codes">
                    @foreach(session('recovery_codes') as $code)
                        <li>{{ $code }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-header">
            <div>
                <h2>{{ __('Two-factor sign-in') }}</h2>
                <p>{{ __('After the password, sign-in asks for a code from an authenticator app (Google Authenticator, Microsoft Authenticator, Authy, 1Password).') }}</p>
            </div>
            @if($enabled)
                <span class="badge badge-success badge-dot">{{ __('On') }}</span>
            @else
                <span class="badge badge-warning badge-dot">{{ __('Off') }}</span>
            @endif
        </div>
        <div class="card-body stack" style="gap: 20px">
            @if($enabled)
                <p class="muted">{{ trans_choice(':count recovery code left|:count recovery codes left', $codesLeft, ['count' => $codesLeft]) }}</p>

                <form method="POST" action="{{ route('admin.security.recovery-codes') }}" class="stack" style="gap: 12px">
                    @csrf
                    <div class="field">
                        <label class="label" for="password-codes">{{ __('Password') }}</label>
                        <input class="input" style="max-width: 280px" id="password-codes" type="password" name="password" required autocomplete="current-password">
                        @error('password')<span class="field-error">{{ $message }}</span>@enderror
                    </div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap">
                        <button class="btn btn-secondary" type="submit"><x-icon name="refresh" /> {{ __('Create new recovery codes') }}</button>
                        <button class="btn btn-danger" type="submit" formaction="{{ route('admin.security.disable') }}" onclick="return confirm({{ Js::from(__('Turn off two-factor sign-in?')) }})"><x-icon name="power" /> {{ __('Turn off') }}</button>
                    </div>
                    <span class="help">{{ __('Enter your password to create new codes or to turn two-factor sign-in off.') }}</span>
                </form>
            @elseif($secret)
                <div class="setup-grid">
                    <div class="qr">{!! $qr !!}</div>
                    <div class="stack" style="gap: 12px; flex: 1; min-width: 240px">
                        <ol class="muted" style="padding-left: 18px; display: grid; gap: 6px">
                            <li>{{ __('Open your authenticator app and scan the code.') }}</li>
                            <li>{{ __('Or type this key in by hand:') }} <code class="mono" style="word-break: break-all">{{ $secret }}</code></li>
                            <li>{{ __('Enter the 6 digits the app shows.') }}</li>
                        </ol>
                        <form method="POST" action="{{ route('admin.security.confirm') }}" class="stack" style="gap: 12px">
                            @csrf
                            <div class="field">
                                <label class="label" for="code">{{ __('Code') }}</label>
                                <input class="input code-input" id="code" name="code" required autofocus inputmode="numeric" autocomplete="one-time-code" maxlength="10">
                                @error('code')<span class="field-error">{{ $message }}</span>@enderror
                            </div>
                            <div><button class="btn btn-primary" type="submit"><x-icon name="check" /> {{ __('Turn on') }}</button></div>
                        </form>
                    </div>
                </div>
            @else
                <form method="POST" action="{{ route('admin.security.start') }}">
                    @csrf
                    <button class="btn btn-primary" type="submit"><x-icon name="shield" /> {{ __('Set up two-factor sign-in') }}</button>
                </form>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2>{{ __('Recent sign-ins') }}</h2>
                <p>{{ __('The last 30 attempts to sign in to the panel.') }}</p>
            </div>
        </div>
        @if($events->isEmpty())
            <div class="empty" style="padding: 28px 20px"><p>{{ __('No sign-ins recorded yet.') }}</p></div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('User') }}</th><th>{{ __('What happened') }}</th><th class="hide-sm">{{ __('IP address') }}</th><th class="hide-sm">{{ __('Browser') }}</th><th class="right">{{ __('When') }}</th></tr></thead>
                    <tbody>
                    @foreach($events as $event)
                        <tr>
                            <td class="cell-main">{{ $event->username }}</td>
                            <td><span class="badge {{ $event->isProblem() ? 'badge-danger' : 'badge-success' }}">{{ $event->label() }}</span></td>
                            <td class="hide-sm mono muted">{{ $event->ip }}</td>
                            <td class="hide-sm muted truncate" style="max-width: 220px" title="{{ $event->user_agent }}">{{ $event->user_agent }}</td>
                            <td class="right muted" title="{{ $event->created_at }}">{{ $event->created_at->translatedFormat('M j, H:i') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
