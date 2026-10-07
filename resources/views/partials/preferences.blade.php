{{-- Language (with flags) and light/dark mode, shared by the admin sidebar and the sign-in page.
     Both work as plain forms; the script below makes the theme switch instant. --}}
@php
    $locales = config('app.locales');
    $current = $locales[app()->getLocale()] ?? reset($locales);
    $theme = \App\Support\Theme::current();
    $themes = [
        'light' => ['sun', __('Light')],
        'dark' => ['moon', __('Dark')],
        'system' => ['monitor', __('System')],
    ];
@endphp
<div class="prefs">
    <details class="lang-menu">
        <summary class="lang-current" aria-label="{{ __('Language') }}: {{ $current['name'] }}">
            <img src="{{ asset('images/flags/'.$current['flag'].'.svg') }}" alt="" class="flag">
            <span class="truncate">{{ $current['name'] }}</span>
            <x-icon name="chevron-down" class="chevron" />
        </summary>
        <form method="POST" action="{{ route('locale.update') }}" class="lang-options">
            @csrf
            @foreach($locales as $code => $locale)
                <button type="submit" name="locale" value="{{ $code }}" lang="{{ $code }}" class="lang-option" @if($code === app()->getLocale()) aria-current="true" @endif>
                    <img src="{{ asset('images/flags/'.$locale['flag'].'.svg') }}" alt="" class="flag">
                    <span>{{ $locale['name'] }}</span>
                    @if($code === app()->getLocale())<x-icon name="check" class="lang-check" />@endif
                </button>
            @endforeach
        </form>
    </details>

    <form method="POST" action="{{ route('theme.update') }}" class="theme-switch" role="group" aria-label="{{ __('Theme') }}">
        @csrf
        @foreach($themes as $value => [$icon, $label])
            <button type="submit" name="theme" value="{{ $value }}" title="{{ $label }}" aria-label="{{ $label }}" aria-pressed="{{ $theme === $value ? 'true' : 'false' }}">
                <x-icon :name="$icon" />
            </button>
        @endforeach
    </form>
</div>

@once
<script>
    // Theme: apply at once and remember it in the "theme" cookie (same one ThemeController sets).
    document.addEventListener('submit', (event) => {
        if (!event.target.matches('.theme-switch') || !event.submitter) return;
        event.preventDefault();
        const theme = event.submitter.value;
        document.documentElement.dataset.theme = theme;
        document.cookie = theme === 'system'
            ? 'theme=; path=/; max-age=0; samesite=lax'
            : `theme=${theme}; path=/; max-age=157680000; samesite=lax`;
        document.querySelectorAll('.theme-switch button').forEach((b) => b.setAttribute('aria-pressed', String(b.value === theme)));
    });

    // Close the language menu when clicking elsewhere or pressing Escape.
    document.addEventListener('click', (event) => {
        document.querySelectorAll('.lang-menu[open]').forEach((menu) => { if (!menu.contains(event.target)) menu.open = false; });
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') document.querySelectorAll('.lang-menu[open]').forEach((menu) => menu.open = false);
    });
</script>
@endonce
