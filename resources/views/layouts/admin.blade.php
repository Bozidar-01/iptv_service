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
<script>
    // Before the first paint: keep the menu hidden if it was hidden on the last page (no slide on page load)
    try { if (localStorage.getItem('nav') === 'hidden') document.body.classList.add('nav-collapsed'); } catch (e) {}
</script>
@php
    // The menu and the tab bars live in App\Support\AdminNav
    $nav = \App\Support\AdminNav::sidebar(Auth::user());
    $tabs = \App\Support\AdminNav::tabs(Auth::user());
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
                    <a href="{{ route($item['route']) }}" class="nav-link {{ request()->routeIs(...$item['match']) ? 'active' : '' }}" @if(request()->routeIs(...$item['match'])) aria-current="page" @endif>
                        <x-icon :name="$item['icon']" />
                        <span class="nav-label">{{ $item['label'] }}</span>
                    </a>
                @endif
            @endforeach
        </nav>

        <div class="sidebar-footer">
            <span class="avatar">{{ strtoupper(substr(Auth::user()->username, 0, 1)) }}</span>
            <div class="sidebar-user">
                <strong>{{ Auth::user()->username }}</strong>
                <span>{{ Auth::user()->isAdmin() ? __('Administrator') : __('Reseller') }}</span>
            </div>
            <a href="{{ route('admin.security.index') }}" class="btn btn-ghost btn-icon" title="{{ __('Security: two-step sign-in and your sign-ins') }}" aria-label="{{ __('Security') }}" style="color: var(--sidebar-text)">
                <x-icon name="shield" />
            </a>
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
        {{-- One header for all screen sizes: on a phone the menu button opens the menu over the page; on a wider screen
             the sidebar button shrinks the menu to a strip of icons and back --}}
        <header class="header-bar">
            <button type="button" class="btn btn-ghost btn-icon header-menu" aria-label="{{ __('Open menu') }}" onclick="document.body.classList.add('nav-open')">
                <x-icon name="menu" />
            </button>
            <button type="button" class="btn btn-ghost btn-icon header-toggle" id="nav-toggle" aria-controls="sidebar" aria-expanded="true"
                    title="{{ __('Show or hide the menu') }}" aria-label="{{ __('Show or hide the menu') }}">
                <x-icon name="sidebar" />
            </button>
            <span class="header-brand"><x-brand-name /></span>
            <div class="header-title" aria-hidden="true">@yield('title', __('Dashboard'))</div>
            <div class="header-end">
                @include('partials.preferences')
                @include('admin.partials.notifications')
            </div>
        </header>

        <main class="main">
            <div class="@yield('container', 'container')">
                @if($tabs !== [])
                    <nav class="tabs" aria-label="{{ __('Pages in this section') }}">
                        @foreach($tabs as $tab)
                            <a href="{{ route($tab['route']) }}" @if(request()->routeIs(...$tab['match'])) aria-current="page" @endif>{{ $tab['label'] }}</a>
                        @endforeach
                    </nav>
                @endif
                @include('admin.partials.flash')
                @yield('content')
            </div>
        </main>
    </div>
</div>

<script>
    // Shrink the menu to a strip of icons and back; remembered in this browser
    (() => {
        const toggle = document.getElementById('nav-toggle');
        const sync = () => toggle.setAttribute('aria-expanded', String(!document.body.classList.contains('nav-collapsed')));
        sync();
        toggle.addEventListener('click', () => {
            document.body.classList.toggle('nav-collapsed');
            sync();
            try { localStorage.setItem('nav', document.body.classList.contains('nav-collapsed') ? 'hidden' : 'shown'); } catch (e) {}
        });
        // Animate only changes made after the page has loaded
        setTimeout(() => document.body.classList.add('nav-ready'), 100);

        // In the icon strip, pointing at (or tabbing to) an icon shows the page name next to it
        const tip = document.createElement('div');
        tip.className = 'nav-tip';
        tip.setAttribute('role', 'tooltip');
        tip.hidden = true;
        document.body.appendChild(tip);
        const show = (event) => {
            const link = event.target.closest('.sidebar a.nav-link, .sidebar-footer .btn');
            if (!link || !document.body.classList.contains('nav-collapsed') || window.innerWidth <= 860) return;
            const box = link.getBoundingClientRect();
            tip.textContent = link.querySelector('.nav-label')?.textContent || link.getAttribute('aria-label') || '';
            tip.style.top = `${box.top + box.height / 2}px`;
            tip.style.left = `${box.right + 10}px`;
            tip.hidden = false;
        };
        const hide = () => { tip.hidden = true; };
        const sidebar = document.getElementById('sidebar');
        sidebar.addEventListener('mouseover', show);
        sidebar.addEventListener('focusin', show);
        sidebar.addEventListener('mouseleave', hide);
        sidebar.addEventListener('focusout', hide);
        sidebar.querySelector('nav').addEventListener('scroll', hide);
        toggle.addEventListener('click', hide);
    })();

    // On a narrow screen the tab bar scrolls: show the current tab
    document.querySelector('.tabs [aria-current="page"]')?.scrollIntoView({ block: 'nearest', inline: 'center' });

    // Once the large page title scrolls under the header bar, show it small in the bar (like Apple's apps)
    const pageTitle = document.querySelector('.page-header h1');
    if (pageTitle && 'IntersectionObserver' in window) {
        new IntersectionObserver(([entry]) => document.body.classList.toggle('scrolled', !entry.isIntersecting && entry.boundingClientRect.top < 0), { rootMargin: '-56px 0px 0px 0px' }).observe(pageTitle);
    }

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

    // Show/hide a password field: <button data-reveal="#input-id">
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-reveal]');
        if (!button) return;
        const input = document.querySelector(button.dataset.reveal);
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        const label = button.querySelector('[data-reveal-label]');
        if (label) label.textContent = show ? @json(__('Hide current password')) : @json(__('Show current password'));
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
