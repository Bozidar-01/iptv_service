<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'IPTV Admin') }} - @yield('title', 'Dashboard')</title>

    <!-- Meta Tags -->
    <meta name="theme-color" content="#09090b">

    <!-- Styles - Direct Public Load for 100% Reliability -->
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
</head>
<body>
    <div class="app-wrapper">
        <!-- Ultra-Premium Sidebar -->
        <aside class="app-sidebar shadow-hard">
            <div class="brand-section">
                <h1 class="brand-title">IPTV CONTROL</h1>
            </div>

            <nav class="nav-container">
                <div class="nav-group-label">Core Infrastructure</div>
                
                <a href="{{ route('admin.dashboard') }}" class="nav-item {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                    Network Health
                </a>

                <div class="nav-group-label">Asset Management</div>

                <a href="{{ route('admin.users.index') }}" class="nav-item {{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    User Accounts
                </a>

                <a href="{{ route('admin.channels.index') }}" class="nav-item {{ request()->routeIs('admin.channels.*') ? 'active' : '' }}">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                    Stream Channels
                </a>

                <a href="{{ route('admin.categories.index') }}" class="nav-item {{ request()->routeIs('admin.categories.*') ? 'active' : '' }}">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                    Content Groups
                </a>

                <a href="{{ route('admin.bouquets.index') }}" class="nav-item {{ request()->routeIs('admin.bouquets.*') ? 'active' : '' }}">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                    Subscription Packs
                </a>

                <div class="nav-group-label">Real-Time Data</div>

                <a href="{{ route('admin.logs.index') }}" class="nav-item {{ request()->routeIs('admin.logs.index') ? 'active' : '' }}">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Activity Logs
                </a>
            </nav>

            <div class="sidebar-user">
                <div class="avatar-sphere">
                    {{ strtoupper(substr(Auth::user()->username, 0, 1)) }}
                </div>
                <div style="flex: 1;">
                    <div style="font-weight: 800; font-size: 0.9rem; color: var(--text-white);">{{ Auth::user()->username }}</div>
                    <div style="font-size: 0.65rem; font-weight: 700; color: var(--text-dim); text-transform: uppercase;">Superuser</div>
                </div>
                <form method="POST" action="{{ route('logout') }}" id="logout-form">
                    @csrf
                    <button type="submit" style="background: none; border: none; color: var(--text-dim); cursor: pointer; padding: 4px; hover: color: var(--danger);">
                        <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                    </button>
                </form>
            </div>
        </aside>

        <!-- Dynamic Content Viewport -->
        <main class="app-content">
            <div class="fade-in">
                @yield('content')
            </div>
        </main>
    </div>
</body>
</html>
