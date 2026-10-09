@extends('layouts.admin')

@section('title', __('Users'))

@section('content')
<div class="page-header">
    <div>
        <h1>{{ __('Users') }}</h1>
        <p>{{ __('Subscribers who watch through their playlist link, and admins who manage this panel.') }}</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('admin.users.import') }}" class="btn btn-secondary"><x-icon name="upload" /> {{ __('Import') }}</a>
        <a href="{{ route('admin.users.export', array_filter($filters)) }}" class="btn btn-secondary"><x-icon name="copy" /> {{ __('Export') }}</a>
        <a href="{{ route('admin.users.trial') }}" class="btn btn-secondary"><x-icon name="clock" /> {{ __('New trial') }}</a>
        <a href="{{ route('admin.users.create') }}" class="btn btn-primary"><x-icon name="plus" /> {{ __('New user') }}</a>
    </div>
</div>

<div class="card">
    <form method="GET" action="{{ route('admin.users.index') }}" class="toolbar">
        <div class="input-group">
            <x-icon name="search" />
            <input class="input" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="{{ __('Search by username…') }}">
        </div>
        <select class="select" name="status" onchange="this.form.submit()" aria-label="{{ __('Status') }}">
            <option value="">{{ __('All statuses') }}</option>
            <option value="active" @selected(($filters['status'] ?? '') === 'active')>{{ __('Active') }}</option>
            <option value="expired" @selected(($filters['status'] ?? '') === 'expired')>{{ __('Expired') }}</option>
            <option value="disabled" @selected(($filters['status'] ?? '') === 'disabled')>{{ __('Disabled') }}</option>
        </select>
        @if(array_filter($filters))
            <a href="{{ route('admin.users.index') }}" class="btn btn-ghost btn-sm"><x-icon name="x" /> {{ __('Clear') }}</a>
        @endif
    </form>

    <form method="POST" action="{{ route('admin.users.bulk') }}" id="bulk-form" class="bulk-bar" hidden>
        @csrf
        <input type="hidden" name="scope" value="selected" id="bulk-scope">
        @foreach(array_filter($filters) as $key => $value)
            <input type="hidden" name="filters[{{ $key }}]" value="{{ $value }}">
        @endforeach

        <strong id="bulk-count">{{ __(':count selected', ['count' => 0]) }}</strong>
        @if($users->total() > $users->count())
            <button type="button" class="btn btn-ghost btn-sm" id="bulk-all" hidden>{{ __('Select all :count matching', ['count' => number_format($users->total())]) }}</button>
        @endif
        <span class="spacer" style="flex: 1"></span>
        <select class="select" name="action" id="bulk-action" required aria-label="{{ __('Bulk action') }}">
            <option value="">{{ __('Choose action…') }}</option>
            <option value="enable">{{ __('Enable') }}</option>
            <option value="disable">{{ __('Disable') }}</option>
            <option value="extend">{{ __('Renew') }}</option>
            <option value="add_bouquet">{{ __('Add a bouquet') }}</option>
            <option value="remove_bouquet">{{ __('Remove a bouquet') }}</option>
            <option value="delete">{{ __('Delete') }}</option>
        </select>
        <select class="select" name="months" id="bulk-months" hidden aria-label="{{ __('How long') }}">
            @foreach(\App\Http\Controllers\Admin\UserController::RENEW_MONTHS as $months)
                <option value="{{ $months }}">{{ trans_choice(':count month|:count months', $months, ['count' => $months]) }}</option>
            @endforeach
        </select>
        <select class="select" name="bouquet_id" id="bulk-bouquet" hidden aria-label="{{ __('Bouquet') }}">
            <option value="">{{ __('Choose bouquet…') }}</option>
            @foreach($bulkBouquets as $bouquet)
                <option value="{{ $bouquet->id }}">{{ $bouquet->name }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-primary btn-sm">{{ __('Apply') }}</button>
    </form>

    @if($users->isEmpty())
        <div class="empty">
            <div class="empty-icon"><x-icon name="users" /></div>
            <h3>{{ array_filter($filters) ? __('No users match') : __('No users yet') }}</h3>
            @unless(array_filter($filters))
                <p>{{ __('Create a user, give them a bouquet, and share their playlist link.') }}</p>
                <a href="{{ route('admin.users.create') }}" class="btn btn-primary"><x-icon name="plus" /> {{ __('New user') }}</a>
            @endunless
        </div>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th class="shrink"><input type="checkbox" id="select-page" aria-label="{{ __('Select all on this page') }}" style="accent-color: var(--primary)"></th><th>{{ __('User') }}</th><th>{{ __('Status') }}</th><th class="hide-sm">{{ __('Expires') }}</th><th class="hide-sm right">{{ __('Connections') }}</th><th></th></tr>
                </thead>
                <tbody>
                @foreach($users as $user)
                    <tr>
                        <td>@if($user->role === 'user')<input type="checkbox" name="ids[]" value="{{ $user->id }}" form="bulk-form" class="row-check" aria-label="{{ __('Select :name', ['name' => $user->username]) }}" style="accent-color: var(--primary)">@endif</td>
                        <td>
                            <div class="cell-flex">
                                <span class="avatar">{{ strtoupper(substr($user->username, 0, 1)) }}</span>
                                <div style="min-width: 0">
                                    <a href="{{ route('admin.users.show', $user) }}" class="cell-main">{{ $user->username }}</a>
                                    @if($user->isAdmin()) <span class="badge badge-primary">{{ __('Admin') }}</span> @endif
                                    @if($user->isReseller()) <span class="badge badge-info">{{ __('Reseller') }}</span> @endif
                                    <div class="cell-sub truncate">
                                        {{ $user->bouquets->pluck('name')->join(', ') ?: __('No bouquets') }}
                                        @if($user->owner && Auth::user()->isAdmin()) · {{ __('Reseller: :name', ['name' => $user->owner->username]) }} @endif
                                        @if($user->isReseller()) · {{ $user->usesCredits() ? __(':count credits', ['count' => number_format($user->credits)]) : __('Unlimited credits') }} @endif
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>@include('admin.partials.user-status', ['user' => $user])</td>
                        <td class="hide-sm muted">
                            @if($user->expiration_date)
                                <span title="{{ $user->expiration_date->toDateString() }}">{{ $user->expiration_date->translatedFormat('M j, Y') }}</span>
                            @else
                                {{ __('Never') }}
                            @endif
                        </td>
                        <td class="hide-sm right">{{ $user->max_connections }}</td>
                        <td class="shrink">
                            <div class="actions">
                                <a href="{{ route('admin.users.show', $user) }}" class="btn btn-secondary btn-sm"><x-icon name="link" /> {{ __('Playlist') }}</a>
                                <form method="POST" action="{{ route('admin.users.toggle', $user) }}" class="inline-form">
                                    @csrf
                                    <button class="btn btn-ghost btn-icon" title="{{ $user->status === 'active' ? __('Disable') : __('Enable') }}" aria-label="{{ $user->status === 'active' ? __('Disable :name', ['name' => $user->username]) : __('Enable :name', ['name' => $user->username]) }}"><x-icon name="power" /></button>
                                </form>
                                <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-ghost btn-icon" title="{{ __('Edit') }}" aria-label="{{ __('Edit :name', ['name' => $user->username]) }}"><x-icon name="edit" /></a>
                                @unless($user->is(auth()->user()))
                                    <form method="POST" action="{{ route('admin.users.destroy', $user) }}" class="inline-form" data-confirm="{{ __('Delete :name? Their playlist stops working immediately.', ['name' => $user->username]) }}">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-danger-ghost btn-icon" title="{{ __('Delete') }}" aria-label="{{ __('Delete :name', ['name' => $user->username]) }}"><x-icon name="trash" /></button>
                                    </form>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $users->links('admin.partials.pagination') }}
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
    const months = document.getElementById('bulk-months');
    const bouquet = document.getElementById('bulk-bouquet');
    const total = {{ $users->total() }};
    const text = {{ Js::from([
        'all' => __('All :count matching users selected'),
        'selected' => __(':count selected'),
        'confirmDelete' => __('Delete :count users? Their playlists stop working immediately.'),
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
    action.addEventListener('change', () => {
        const needsBouquet = ['add_bouquet', 'remove_bouquet'].includes(action.value);
        bouquet.hidden = !needsBouquet;
        bouquet.required = needsBouquet;
        months.hidden = action.value !== 'extend';
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
