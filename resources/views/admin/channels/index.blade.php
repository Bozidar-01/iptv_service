@extends('layouts.admin')

@section('title', __('Channels'))

@section('content')
<div class="page-header">
    <div>
        <h1>{{ __('Channels') }}</h1>
        <p>{{ __('Everything users can watch. Disabled channels are hidden from playlists.') }}</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('admin.sources.index') }}" class="btn btn-secondary"><x-icon name="source" /> {{ __('Import from source') }}</a>
        <a href="{{ route('admin.channels.create') }}" class="btn btn-primary"><x-icon name="plus" /> {{ __('Add channel') }}</a>
    </div>
</div>

<div class="card">
    <form method="GET" action="{{ route('admin.channels.index') }}" class="toolbar" id="filters">
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
        <select class="select" name="source" onchange="this.form.submit()" aria-label="{{ __('Source') }}">
            <option value="">{{ __('All sources') }}</option>
            <option value="manual" @selected(($filters['source'] ?? '') === 'manual')>{{ __('Added manually') }}</option>
            @foreach($sources as $source)
                <option value="{{ $source->id }}" @selected(($filters['source'] ?? '') == $source->id)>{{ $source->name }}</option>
            @endforeach
        </select>
        <select class="select" name="bouquet" onchange="this.form.submit()" aria-label="{{ __('Bouquet') }}">
            <option value="">{{ __('Any bouquet') }}</option>
            @foreach($bouquets as $bouquet)
                <option value="{{ $bouquet->id }}" @selected(($filters['bouquet'] ?? '') == $bouquet->id)>{{ __('In :name', ['name' => $bouquet->name]) }}</option>
            @endforeach
        </select>
        <select class="select" name="status" onchange="this.form.submit()" aria-label="{{ __('Status') }}">
            <option value="">{{ __('Any status') }}</option>
            <option value="active" @selected(($filters['status'] ?? '') === 'active')>{{ __('Enabled') }}</option>
            <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>{{ __('Disabled') }}</option>
        </select>
        @if(array_filter($filters))
            <a href="{{ route('admin.channels.index') }}" class="btn btn-ghost btn-sm"><x-icon name="x" /> {{ __('Clear') }}</a>
        @endif
    </form>

    <form method="POST" action="{{ route('admin.channels.bulk') }}" id="bulk-form" class="bulk-bar" hidden>
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
        <select class="select" name="action" id="bulk-action" required aria-label="{{ __('Bulk action') }}">
            <option value="">{{ __('Choose action…') }}</option>
            <option value="enable">{{ __('Enable') }}</option>
            <option value="disable">{{ __('Disable') }}</option>
            <option value="add_to_bouquet">{{ __('Add to bouquet') }}</option>
            <option value="remove_from_bouquet">{{ __('Remove from bouquet') }}</option>
            <option value="delete">{{ __('Delete') }}</option>
        </select>
        <select class="select" name="bouquet_id" id="bulk-bouquet" hidden aria-label="{{ __('Bouquet') }}">
            <option value="">{{ __('Choose bouquet…') }}</option>
            @foreach($bouquets as $bouquet)
                <option value="{{ $bouquet->id }}">{{ $bouquet->name }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-primary btn-sm">{{ __('Apply') }}</button>
    </form>

    @if($channels->isEmpty())
        <div class="empty">
            <div class="empty-icon"><x-icon name="tv" /></div>
            @if(array_filter($filters))
                <h3>{{ __('No channels match these filters') }}</h3>
                <a href="{{ route('admin.channels.index') }}" class="btn btn-secondary">{{ __('Clear filters') }}</a>
            @else
                <h3>{{ __('No channels yet') }}</h3>
                <p>{{ __('Import them from a provider playlist, or add one by hand.') }}</p>
                <a href="{{ route('admin.sources.create') }}" class="btn btn-primary"><x-icon name="source" /> {{ __('Add a source') }}</a>
            @endif
        </div>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th class="shrink"><input type="checkbox" id="select-page" aria-label="{{ __('Select all on this page') }}" style="accent-color: var(--primary)"></th>
                    <th>{{ __('Channel') }}</th>
                    <th>{{ __('Category') }}</th>
                    <th class="hide-sm">{{ __('Source') }}</th>
                    <th class="hide-sm right">{{ __('Bouquets') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th></th>
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
                                <div style="min-width: 0">
                                    <div class="cell-main truncate">{{ $channel->name }}</div>
                                    <div class="cell-sub truncate mono" style="max-width: 280px">{{ parse_url($channel->stream_url, PHP_URL_HOST) }}</div>
                                </div>
                            </div>
                        </td>
                        <td>{!! $channel->category ? e($channel->category->name) : '<span class="faint">'.e(__('None')).'</span>' !!}</td>
                        <td class="hide-sm">{!! $channel->source ? e($channel->source->name) : '<span class="faint">'.e(__('Manual')).'</span>' !!}</td>
                        <td class="hide-sm right">{{ $channel->bouquets_count }}</td>
                        <td>
                            @if($channel->is_active)
                                <span class="badge badge-success badge-dot">{{ __('Enabled') }}</span>
                            @else
                                <span class="badge badge-dot">{{ __('Disabled') }}</span>
                            @endif
                        </td>
                        <td class="shrink">
                            <div class="actions">
                                <a href="{{ route('admin.channels.edit', $channel) }}" class="btn btn-ghost btn-icon" title="{{ __('Edit') }}" aria-label="{{ __('Edit :name', ['name' => $channel->name]) }}"><x-icon name="edit" /></a>
                                <form method="POST" action="{{ route('admin.channels.destroy', $channel) }}" class="inline-form" data-confirm="{{ __('Delete :name?', ['name' => $channel->name]) }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-danger-ghost btn-icon" title="{{ __('Delete') }}" aria-label="{{ __('Delete :name', ['name' => $channel->name]) }}"><x-icon name="trash" /></button>
                                </form>
                            </div>
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
    const action = document.getElementById('bulk-action');
    const bouquet = document.getElementById('bulk-bouquet');
    const total = {{ $channels->total() }};
    const text = {{ Js::from([
        'all' => __('All :count matching channels selected'),
        'selected' => __(':count selected'),
        'confirmDelete' => __('Delete :count channels? Imported channels come back on the next sync unless you remove them from the source.'),
    ]) }};
    const fill = (template, n) => template.replace(':count', n.toLocaleString());

    const update = () => {
        const selected = rows.filter((r) => r.checked).length;
        const filtered = scope.value === 'filtered';
        form.hidden = selected === 0 && !filtered;
        count.textContent = filtered ? fill(text.all, total) : fill(text.selected, selected);
        if (pageBox) pageBox.checked = selected === rows.length && rows.length > 0;
        if (allBtn) allBtn.hidden = filtered || selected !== rows.length;
    };

    rows.forEach((r) => r.addEventListener('change', () => { scope.value = 'selected'; update(); }));
    pageBox?.addEventListener('change', () => { rows.forEach((r) => r.checked = pageBox.checked); scope.value = 'selected'; update(); });
    allBtn?.addEventListener('click', () => { scope.value = 'filtered'; update(); });
    action.addEventListener('change', () => {
        const needsBouquet = ['add_to_bouquet', 'remove_from_bouquet'].includes(action.value);
        bouquet.hidden = !needsBouquet;
        bouquet.required = needsBouquet;
    });
    form.addEventListener('submit', (event) => {
        const n = scope.value === 'filtered' ? total : rows.filter((r) => r.checked).length;
        if (action.value === 'delete' && !confirm(fill(text.confirmDelete, n))) {
            event.preventDefault();
        }
    });
})();
</script>
@endpush
