{{-- Shared shell of the error pages (404, 500, …). It must work even when the app is broken (no database, no
     session), so it asks nothing of them: the sign-in check is wrapped in rescue(). --}}
@php
    $signedIn = rescue(fn () => auth()->check(), false, false);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ rescue(fn () => \App\Support\Theme::current(), 'system', false) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
    @include('admin.partials.favicons')
</head>
<body>
<main class="auth error-page">
    <div class="error-card">
        <span class="brand-mark"><x-icon name="nebotv" /></span>
        <div class="error-code">@yield('code')</div>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <div class="error-actions">
            <button type="button" class="btn {{ $signedIn ? 'btn-secondary' : 'btn-primary' }}" onclick="history.length > 1 ? history.back() : location.assign('{{ url('/') }}')"><x-icon name="arrow-left" /> {{ __('Go back') }}</button>
            @if($signedIn)
                <a href="{{ route('admin.dashboard') }}" class="btn btn-primary"><x-icon name="dashboard" /> {{ __('Go to the dashboard') }}</a>
            @endif
        </div>
    </div>
</main>
</body>
</html>
