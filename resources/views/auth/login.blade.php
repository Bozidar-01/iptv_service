<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · IPTV Panel</title>
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}?v={{ filemtime(public_path('css/admin.css')) }}">
</head>
<body>
<div class="auth">
    <div class="auth-card">
        <div class="auth-brand">
            <span class="brand-mark"><x-icon name="tv" /></span>
            <div>
                <h1>IPTV Panel</h1>
                <p>Sign in to manage channels and subscribers</p>
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
                    <label class="label" for="username">Username</label>
                    <input class="input" id="username" name="username" value="{{ old('username') }}" required autofocus autocomplete="username">
                </div>

                <div class="field">
                    <label class="label" for="password">Password</label>
                    <input class="input" id="password" type="password" name="password" required autocomplete="current-password">
                </div>

                <label class="check">
                    <input type="checkbox" name="remember" value="1">
                    <span>Keep me signed in</span>
                </label>

                <button type="submit" class="btn btn-primary btn-block" style="height: 40px">Sign in</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
