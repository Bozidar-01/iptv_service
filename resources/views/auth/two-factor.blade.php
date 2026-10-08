<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ \App\Support\Theme::current() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Two-factor sign-in') }} · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}?v={{ filemtime(public_path('css/admin.css')) }}">
    @include('admin.partials.favicons')
</head>
<body>
<div class="auth">
    <div class="auth-card">
        <div class="auth-brand">
            <span class="brand-mark"><x-icon name="nebotv" /></span>
            <div>
                <h1><x-brand-name /></h1>
                <p>{{ __('Enter the code from your authenticator app') }}</p>
            </div>
        </div>

        <div class="card">
            <form method="POST" action="{{ route('two-factor.verify') }}" class="card-body stack" style="gap: 16px">
                @csrf

                @if($errors->any())
                    <div class="alert alert-danger" style="margin: 0" role="alert">
                        <x-icon name="alert" />
                        <div class="alert-body">{{ $errors->first() }}</div>
                    </div>
                @endif

                <div class="field">
                    <label class="label" for="code">{{ __('Code') }}</label>
                    <input class="input mono" id="code" name="code" required autofocus autocomplete="one-time-code" inputmode="text" maxlength="20">
                    <span class="help">{{ __('The 6 digits from the app, or one of your recovery codes.') }}</span>
                </div>

                <button type="submit" class="btn btn-primary btn-block" style="height: 40px">{{ __('Sign in') }}</button>
                <a href="{{ route('login') }}" class="btn btn-ghost btn-block">{{ __('Back to sign in') }}</a>
            </form>
        </div>

        @include('partials.preferences')
    </div>
</div>
</body>
</html>
