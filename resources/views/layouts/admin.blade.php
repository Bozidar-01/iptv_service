<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ \App\Support\Theme::current() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('Dashboard')) · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}?v={{ filemtime(public_path('css/admin.css')) }}">
    @include('admin.partials.favicons')
</head>
<body>
@php
    $nav = [
        ['section' => __('Overview')],
        ['route' => 'admin.dashboard', 'match' => 'admin.dashboard', 'icon' => 'dashboard', 'label' => __('Dashboard')],
        ['section' => __('Content')],
        ['route' => 'admin.sources.index', 'match' => 'admin.sources.*', 'icon' => 'source', 'label' => __('Sources')],
        ['route' => 'admin.channels.index', 'match' => 'admin.channels.*', 'icon' => 'tv', 'label' => __('Channels')],
        ['route' => 'admin.categories.index', 'match' => 'admin.categories.*', 'icon' => 'folder', 'label' => __('Categories')],
        ['route' => 'admin.bouquets.index', 'match' => 'admin.bouquets.*', 'icon' => 'package', 'label' => __('Bouquets')],
        ['route' => 'admin.epg.index', 'match' => 'admin.epg.*', 'icon' => 'clock', 'label' => __('TV guide')],
        ['section' => __('Subscribers')],
        ['route' => 'admin.users.index', 'match' => 'admin.users.*', 'icon' => 'users', 'label' => __('Users')],
        ['route' => 'admin.logs.index', 'match' => 'admin.logs.*', 'icon' => 'activity', 'label' => __('Activity')],
        ['route' => 'admin.statistics.index', 'match' => 'admin.statistics.*', 'icon' => 'signal', 'label' => __('Statistics')],
        ['route' => 'admin.alerts.index', 'match' => 'admin.alerts.*', 'icon' => 'bell', 'label' => __('Alerts')],
        ['section' => __('Account')],
        ['route' => 'admin.security.index', 'match' => 'admin.security.*', 'icon' => 'shield', 'label' => __('Security')],
    ];
@endphp
<div class="shell">
    <aside class="sidebar" id="sidebar">
        <a href="{{ route('admin.dashboard') }}" class="brand">
            <span class="brand-mark"><x-icon name="nebotv" /></span>
            <x-brand-name />
        </a>

        <nav>
            @foreach($nav as $item)
                @if(isset($item['section']))
                    <div class="nav-section">{{ $item['section'] }}</div>
                @else
                    <a href="{{ route($item['route']) }}" class="nav-link {{ request()->routeIs($item['match']) ? 'active' : '' }}">
                        <x-icon :name="$item['icon']" />
                        {{ $item['label'] }}
                    </a>
                @endif
            @endforeach
        </nav>

        @include('partials.preferences')

        <div class="sidebar-footer">
            <span class="avatar">{{ strtoupper(substr(Auth::user()->username, 0, 1)) }}</span>
            <div class="sidebar-user">
                <strong>{{ Auth::user()->username }}</strong>
                <span>{{ __('Administrator') }}</span>
            </div>
            <form method="POST" action="{{ route('logout') }}" class="inline-form">
                @csrf
                <button type="submit" class="btn btn-ghost btn-icon" title="{{ __('Sign out') }}" aria-label="{{ __('Sign out') }}" style="color: var(--sidebar-text)">
                    <x-icon name="logout" />
                </button>
            </form>
        </div>
    </aside>
    <div class="nav-overlay" onclick="document.body.classList.remove('nav-open')"></div>

    <div>
        <header class="topbar">
            <button type="button" class="btn btn-ghost btn-icon" aria-label="{{ __('Open menu') }}" onclick="document.body.classList.add('nav-open')">
                <x-icon name="menu" />
            </button>
            <x-brand-name />
            <div class="topbar-end">@include('admin.partials.notifications')</div>
        </header>
        <div class="header-bar">@include('admin.partials.notifications')</div>

        <main class="main">
            <div class="@yield('container', 'container')">
                @include('admin.partials.flash')
                @yield('content')
            </div>
        </main>
    </div>
</div>

<script>
    // Copy-to-clipboard buttons: <button data-copy="#input-id">
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-copy]');
        if (!button) return;
        const input = document.querySelector(button.dataset.copy);
        try {
            await navigator.clipboard.writeText(input.value);
        } catch (e) {
            input.select();
            document.execCommand('copy');
        }
        const label = button.querySelector('[data-copy-label]');
        if (label) {
            const original = label.textContent;
            label.textContent = @json(__('Copied'));
            setTimeout(() => label.textContent = original, 1500);
        }
    });

    // Confirm destructive actions: <form data-confirm="Are you sure?">
    document.addEventListener('submit', (event) => {
        const message = event.target.dataset.confirm;
        if (message && !confirm(message)) event.preventDefault();
    });
</script>
@stack('scripts')
</body>
</html>
