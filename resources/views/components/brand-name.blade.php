@php
    // App name as a wordmark: a trailing "TV" gets the brand gradient (NeboTV → Nebo + TV).
    $name = config('app.name');
    $hasAccent = str_ends_with($name, 'TV') && strlen($name) > 2;
@endphp
<span {{ $attributes->merge(['class' => 'brand-name']) }}>@if($hasAccent){{ substr($name, 0, -2) }}<span class="brand-accent">TV</span>@else{{ $name }}@endif</span>
