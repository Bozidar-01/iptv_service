<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ \App\Support\Theme::current() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Sign in') }} · {{ config('app.name') }}</title>
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
                <p>{{ __('Sign in to manage channels and subscribers') }}</p>
            </div>
        </div>

        <div class="card">
            <form method="POST" action="{{ url('/login') }}" class="card-body stack" style="gap: 16px">
                @csrf

                @if($errors->any())
                    <div class="alert alert-danger" style="margin: 0" role="alert">
                        <x-icon name="alert" />
                        <div class="alert-body">{{ $errors->first() }}</div>
                    </div>
                @endif

                <div class="field">
                    <label class="label" for="username">{{ __('Username') }}</label>
                    <input class="input" id="username" name="username" value="{{ old('username') }}" required autofocus autocomplete="username">
                </div>

                <div class="field">
                    <label class="label" for="password">{{ __('Password') }}</label>
                    <input class="input" id="password" type="password" name="password" required autocomplete="current-password">
                </div>

                <label class="check">
                    <input type="checkbox" name="remember" value="1">
                    <span>{{ __('Keep me signed in') }}</span>
                </label>

                <button type="submit" class="btn btn-primary btn-block" style="height: 40px">{{ __('Sign in') }}</button>
            </form>
        </div>

        @include('partials.preferences')
    </div>
</div>
</body>
</html>
