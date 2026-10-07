@extends('layouts.admin')

@section('title', 'Channels')

@section('content')
<div class="page-header">
    <div>
        <h1>Channels</h1>
        <p>Everything users can watch. Disabled channels are hidden from playlists.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('admin.sources.index') }}" class="btn btn-secondary"><x-icon name="source" /> Import from source</a>
        <a href="{{ route('admin.channels.create') }}" class="btn btn-primary"><x-icon name="plus" /> Add channel</a>
    </div>
</div>

<div class="card">
    <form method="GET" action="{{ route('admin.channels.index') }}" class="toolbar" id="filters">
        <div class="input-group">
            <x-icon name="search" />
            <input class="input" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Search channels…">
        </div>
        <select class="select" name="category" onchange="this.form.submit()" aria-label="Category">
            <option value="">All categories</option>
            <option value="none" @selected(($filters['category'] ?? '') === 'none')>Uncategorized</option>
            @foreach($categories as $category)
                <option value="{{ $category->id }}" @selected(($filters['category'] ?? '') == $category->id)>{{ $category->name }}</option>
            @endforeach
        </select>
        <select class="select" name="source" onchange="this.form.submit()" aria-label="Source">
            <option value="">All sources</option>
            <option value="manual" @selected(($filters['source'] ?? '') === 'manual')>Added manually</option>
            @foreach($sources as $source)
                <option value="{{ $source->id }}" @selected(($filters['source'] ?? '') == $source->id)>{{ $source->name }}</option>
            @endforeach
        </select>
        <select class="select" name="bouquet" onchange="this.form.submit()" aria-label="Bouquet">
            <option value="">Any bouquet</option>
            @foreach($bouquets as $bouquet)
                <option value="{{ $bouquet->id }}" @selected(($filters['bouquet'] ?? '') == $bouquet->id)>In {{ $bouquet->name }}</option>
            @endforeach
        </select>
        <select class="select" name="status" onchange="this.form.submit()" aria-label="Status">
            <option value="">Any status</option>
            <option value="active" @selected(($filters['status'] ?? '') === 'active')>Enabled</option>
            <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Disabled</option>
        </select>
        @if(array_filter($filters))
            <a href="{{ route('admin.channels.index') }}" class="btn btn-ghost btn-sm"><x-icon name="x" /> Clear</a>
        @endif
    </form>

    <form method="POST" action="{{ route('admin.channels.bulk') }}" id="bulk-form" class="bulk-bar" hidden>
        @csrf
        <input type="hidden" name="scope" value="selected" id="bulk-scope">
        @foreach(array_filter($filters) as $key => $value)
            <input type="hidden" name="filters[{{ $key }}]" value="{{ $value }}">
        @endforeach

        <strong id="bulk-count">0 selected</strong>
        @if($channels->total() > $channels->count())
            <button type="button" class="btn btn-ghost btn-sm" id="bulk-all" hidden>Select all {{ number_format($channels->total()) }} matching</button>
        @endif
        <span class="spacer" style="flex: 1"></span>
        <select class="select" name="action" id="bulk-action" required aria-label="Bulk action">
            <option value="">Choose action…</option>
            <option value="enable">Enable</option>
            <option value="disable">Disable</option>
            <option value="add_to_bouquet">Add to bouquet</option>
            <option value="remove_from_bouquet">Remove from bouquet</option>
            <option value="delete">Delete</option>
        </select>
        <select class="select" name="bouquet_id" id="bulk-bouquet" hidden aria-label="Bouquet">
            <option value="">Choose bouquet…</option>
            @foreach($bouquets as $bouquet)
                <option value="{{ $bouquet->id }}">{{ $bouquet->name }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-primary btn-sm">Apply</button>
    </form>

    @if($channels->isEmpty())
        <div class="empty">
            <div class="empty-icon"><x-icon name="tv" /></div>
            @if(array_filter($filters))
                <h3>No channels match these filters</h3>
                <a href="{{ route('admin.channels.index') }}" class="btn btn-secondary">Clear filters</a>
            @else
                <h3>No channels yet</h3>
                <p>Import them from a provider playlist, or add one by hand.</p>
                <a href="{{ route('admin.sources.create') }}" class="btn btn-primary"><x-icon name="source" /> Add a source</a>
            @endif
        </div>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th class="shrink"><input type="checkbox" id="select-page" aria-label="Select all on this page" style="accent-color: var(--primary)"></th>
                    <th>Channel</th>
                    <th>Category</th>
                    <th class="hide-sm">Source</th>
                    <th class="hide-sm right">Bouquets</th>
                    <th>Status</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @foreach($channels as $channel)
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="{{ $channel->id }}" form="bulk-form" class="row-check" aria-label="Select {{ $channel->name }}" style="accent-color: var(--primary)"></td>
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
                        <td>{!! $channel->category ? e($channel->category->name) : '<span class="faint">None</span>' !!}</td>
                        <td class="hide-sm">{!! $channel->source ? e($channel->source->name) : '<span class="faint">Manual</span>' !!}</td>
                        <td class="hide-sm right">{{ $channel->bouquets_count }}</td>
                        <td>
                            @if($channel->is_active)
                                <span class="badge badge-success badge-dot">Enabled</span>
                            @else
                                <span class="badge badge-dot">Disabled</span>
                            @endif
                        </td>
                        <td class="shrink">
                            <div class="actions">
                                <a href="{{ route('admin.channels.edit', $channel) }}" class="btn btn-ghost btn-icon" title="Edit" aria-label="Edit {{ $channel->name }}"><x-icon name="edit" /></a>
                                <form method="POST" action="{{ route('admin.channels.destroy', $channel) }}" class="inline-form" data-confirm="Delete {{ $channel->name }}?">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-danger-ghost btn-icon" title="Delete" aria-label="Delete {{ $channel->name }}"><x-icon name="trash" /></button>
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

    const update = () => {
        const selected = rows.filter((r) => r.checked).length;
        const filtered = scope.value === 'filtered';
        form.hidden = selected === 0 && !filtered;
        count.textContent = filtered ? `All ${total.toLocaleString()} matching channels selected` : `${selected} selected`;
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
        if (action.value === 'delete' && !confirm(`Delete ${n.toLocaleString()} channels? Imported channels come back on the next sync unless you remove them from the source.`)) {
            event.preventDefault();
        }
    });
})();
</script>
@endpush
