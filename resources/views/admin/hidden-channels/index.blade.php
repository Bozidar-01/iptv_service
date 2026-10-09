@extends('layouts.admin')

@section('title', $heading)

@section('content')
<div class="page-header">
    <div>
        @isset($back)
            <a href="{{ route('admin.users.show', $back) }}" class="breadcrumb"><x-icon name="arrow-left" /> {{ $back->username }}</a>
        @endisset
        <h1>{{ $heading }}</h1>
        <p>{{ $intro }}</p>
    </div>
    <div class="page-actions">
        <span class="badge {{ $hiddenTotal ? 'badge-warning' : 'badge-success' }} badge-dot">{{ __(':hidden of :total channels hidden', ['hidden' => number_format($hiddenTotal), 'total' => number_format($total)]) }}</span>
    </div>
</div>

<details class="card" style="margin-bottom: 20px">
    <summary class="card-header" style="cursor: pointer">
        <div>
            <h2>{{ __('By category') }}</h2>
            <p>{{ __('Hide or show a whole category in one click.') }}</p>
        </div>
    </summary>
    <ul class="list">
        @foreach($categoryStats as $row)
            <li>
                <div>
                    <strong>{{ $row['name'] ?? __('Uncategorized') }}</strong>
                    <div class="muted">{{ trans_choice(':count channel|:count channels', $row['total'], ['count' => number_format($row['total'])]) }}@if($row['hidden']) · {{ __(':count hidden', ['count' => number_format($row['hidden'])]) }}@endif</div>
                </div>
                <div class="page-actions">
                    @foreach(['hide' => $hideLabel, 'show' => $showLabel] as $verb => $label)
                        @continue($verb === 'show' && !$row['hidden'])
                        @continue($verb === 'hide' && $row['hidden'] >= $row['total'])
                        <form method="POST" action="{{ $action }}" class="inline-form" @if($verb === 'hide') data-confirm="{{ __('Hide all :count channels of :name?', ['count' => number_format($row['total'] - $row['hidden']), 'name' => $row['name'] ?? __('Uncategorized')]) }}" @endif>
                            @csrf
                            <input type="hidden" name="action" value="{{ $verb }}">
                            <input type="hidden" name="scope" value="filtered">
                            <input type="hidden" name="filters[category]" value="{{ $row['id'] ?? 'none' }}">
                            <button class="btn btn-ghost btn-sm">{{ $verb === 'hide' ? __('Hide all') : __('Show all') }}</button>
                        </form>
                    @endforeach
                </div>
            </li>
        @endforeach
    </ul>
</details>

<div class="card">
    <form method="GET" action="{{ $action }}" class="toolbar" id="filters">
        <div class="input-group">
            <x-icon name="search" />
            <input class="input" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="{{ __('Search channels…') }}">
        </div>
        <select class="select" name="category" onchange="this.form.submit()" aria-label="{{ __('Category') }}">
            <option value="">{{ __('All categories') }}</option>
            <option value="none" @selected(($filters['category'] ?? '') === 'none')>{{ __('Uncategorized') }}</option>
            @foreach($categories as $category)
                <option value="{{ $category->id }}" @selected(($filters['category'] ?? '') == $category->id)>{{ $category->name }}</option>
            @endforeach
        </select>
        <select class="select" name="bouquet" onchange="this.form.submit()" aria-label="{{ __('Bouquet') }}">
            <option value="">{{ __('Any bouquet') }}</option>
            @foreach($bouquets as $bouquet)
                <option value="{{ $bouquet->id }}" @selected(($filters['bouquet'] ?? '') == $bouquet->id)>{{ __('In :name', ['name' => $bouquet->name]) }}</option>
            @endforeach
        </select>
        <select class="select" name="state" onchange="this.form.submit()" aria-label="{{ __('Status') }}">
            <option value="">{{ __('Shown and hidden') }}</option>
            <option value="visible" @selected(($filters['state'] ?? '') === 'visible')>{{ __('Shown') }}</option>
            <option value="hidden" @selected(($filters['state'] ?? '') === 'hidden')>{{ __('Hidden') }}</option>
        </select>
        @if(array_filter($filters))
            <a href="{{ $action }}" class="btn btn-ghost btn-sm"><x-icon name="x" /> {{ __('Clear') }}</a>
        @endif
    </form>

    <form method="POST" action="{{ $action }}" id="bulk-form" class="bulk-bar" hidden>
        @csrf
        <input type="hidden" name="scope" value="selected" id="bulk-scope">
        @foreach(array_filter($filters) as $key => $value)
            <input type="hidden" name="filters[{{ $key }}]" value="{{ $value }}">
        @endforeach

        <strong id="bulk-count">{{ __(':count selected', ['count' => 0]) }}</strong>
        @if($channels->total() > $channels->count())
            <button type="button" class="btn btn-ghost btn-sm" id="bulk-all" hidden>{{ __('Select all :count matching', ['count' => number_format($channels->total())]) }}</button>
        @endif
        <span class="spacer" style="flex: 1"></span>
        <select class="select" name="action" required aria-label="{{ __('Bulk action') }}">
            <option value="">{{ __('Choose action…') }}</option>
            <option value="hide">{{ $hideLabel }}</option>
            <option value="show">{{ $showLabel }}</option>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">{{ __('Apply') }}</button>
    </form>

    @if($channels->isEmpty())
        <div class="empty">
            <div class="empty-icon"><x-icon name="tv" /></div>
            @if(array_filter($filters))
                <h3>{{ __('No channels match these filters') }}</h3>
                <a href="{{ $action }}" class="btn btn-secondary">{{ __('Clear filters') }}</a>
            @else
                <h3>{{ __('No channels in the bouquets yet') }}</h3>
                <p>{{ __('Channels appear here when a bouquet you hold has channels in it.') }}</p>
            @endif
        </div>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th class="shrink"><input type="checkbox" id="select-page" aria-label="{{ __('Select all on this page') }}" style="accent-color: var(--primary)"></th>
                    <th>{{ __('Channel') }}</th>
                    <th class="hide-sm">{{ __('Category') }}</th>
                    <th class="hide-sm">{{ __('Bouquets') }}</th>
                    <th>{{ __('Status') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($channels as $channel)
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="{{ $channel->id }}" form="bulk-form" class="row-check" aria-label="{{ __('Select :name', ['name' => $channel->name]) }}" style="accent-color: var(--primary)"></td>
                        <td>
                            <div class="cell-flex">
                                @if($channel->logo_url)
                                    <img src="{{ $channel->logo_url }}" alt="" class="logo-thumb" loading="lazy" referrerpolicy="no-referrer" onerror="this.style.visibility='hidden'">
                                @else
                                    <span class="logo-fallback">{{ mb_strtoupper(mb_substr($channel->name, 0, 2)) }}</span>
                                @endif
                                <div class="cell-main truncate">{{ $channel->name }}</div>
                            </div>
                        </td>
                        <td class="hide-sm">{!! $channel->category ? e($channel->category->name) : '<span class="faint">'.e(__('None')).'</span>' !!}</td>
                        <td class="hide-sm">
                            @foreach($channel->bouquets as $bouquet)
                                <span class="badge badge-primary">{{ $bouquet->name }}</span>
                            @endforeach
                        </td>
                        <td>
                            @if(in_array($channel->id, $hiddenForUser, true))
                                <span class="badge badge-warning badge-dot">{{ __('Hidden for :name', ['name' => $subject->username]) }}</span>
                            @elseif(in_array($channel->id, $hiddenByOwner, true) && $subject)
                                <span class="badge badge-warning badge-dot">{{ $ownerIsMe ? __('Hidden for all my users') : __('Hidden for all of :name\'s users', ['name' => $ownerName ?? __('the admins')]) }}</span>
                            @elseif(in_array($channel->id, $hiddenByOwner, true))
                                <span class="badge badge-warning badge-dot">{{ __('Hidden') }}</span>
                            @else
                                <span class="badge badge-success badge-dot">{{ __('Shown') }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $channels->links('admin.partials.pagination') }}
    @endif
</div>
@endsection

@push('scripts')
<script>
(() => {
    const form = document.getElementById('bulk-form');
    if (!form) return;
    const rows = [...document.querySelectorAll('.row-check')];
    const pageBox = document.getElementById('select-page');
    const count = document.getElementById('bulk-count');
    const scope = document.getElementById('bulk-scope');
    const allBtn = document.getElementById('bulk-all');
    const total = {{ $channels->total() }};
    const text = {{ Js::from([
        'all' => __('All :count matching channels selected'),
        'selected' => __(':count selected'),
    ]) }};
    const fill = (template, n) => template.replace(':count', n.toLocaleString());

    const update = () => {
        const selected = rows.filter((r) => r.checked).length;
        const filtered = scope.value === 'filtered';
        form.hidden = selected === 0 && !filtered;
        count.textContent = filtered ? fill(text.all, total) : fill(text.selected, selected);
        if (pageBox) pageBox.checked = selected === rows.length && rows.length > 0;
        if (allBtn) allBtn.hidden = filtered || selected !== rows.length || total <= rows.length;
    };

    rows.forEach((r) => r.addEventListener('change', () => { scope.value = 'selected'; update(); }));
    pageBox?.addEventListener('change', () => { rows.forEach((r) => { r.checked = pageBox.checked; }); scope.value = 'selected'; update(); });
    allBtn?.addEventListener('click', () => { scope.value = 'filtered'; update(); });
})();
</script>
@endpush
