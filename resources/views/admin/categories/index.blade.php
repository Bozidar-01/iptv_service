@extends('layouts.admin')

@section('title', __('Categories'))

@section('content')
<div class="page-header">
    <div>
        <h1>{{ __('Categories') }}</h1>
        <p>{{ __('Groups shown in the TV app (e.g. Sports, News). Imported playlists create them automatically.') }}</p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-primary" onclick="document.getElementById('create-dialog').showModal()"><x-icon name="plus" /> {{ __('New category') }}</button>
    </div>
</div>

<div class="card">
    @if($categories->isEmpty())
        <div class="empty">
            <div class="empty-icon"><x-icon name="folder" /></div>
            <h3>{{ __('No categories yet') }}</h3>
            <p>{{ __("They're created when you import a source, or you can add one yourself.") }}</p>
        </div>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Name') }}</th><th class="right">{{ __('Channels') }}</th><th class="right hide-sm">{{ __('Enabled') }}</th><th></th></tr></thead>
                <tbody>
                @foreach($categories as $category)
                    <tr>
                        <td class="cell-main">{{ $category->name }}</td>
                        <td class="right"><a href="{{ route('admin.channels.index', ['category' => $category->id]) }}">{{ number_format($category->channels_count) }}</a></td>
                        <td class="right hide-sm muted">{{ number_format($category->active_channels_count) }}</td>
                        <td class="shrink">
                            <div class="actions">
                                <a href="{{ route('admin.channels.index', ['category' => $category->id]) }}" class="btn btn-ghost btn-sm">{{ __('View channels') }}</a>
                                <button type="button" class="btn btn-ghost btn-icon" title="{{ __('Rename') }}" aria-label="{{ __('Rename :name', ['name' => $category->name]) }}"
                                        data-rename="{{ route('admin.categories.update', $category) }}" data-name="{{ $category->name }}"><x-icon name="edit" /></button>
                                <form method="POST" action="{{ route('admin.categories.destroy', $category) }}" class="inline-form"
                                      data-confirm="{{ __('Delete :name? Its :count channels are kept without a category.', ['name' => $category->name, 'count' => $category->channels_count]) }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-danger-ghost btn-icon" title="{{ __('Delete') }}" aria-label="{{ __('Delete :name', ['name' => $category->name]) }}"><x-icon name="trash" /></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<dialog class="modal" id="create-dialog">
    <form method="POST" action="{{ route('admin.categories.store') }}">
        @csrf
        <div class="card-header"><h2>{{ __('New category') }}</h2></div>
        <div class="card-body field">
            <label class="label" for="create-name">{{ __('Name') }}</label>
            <input class="input" id="create-name" name="name" required>
        </div>
        <div class="card-footer">
            <button type="button" class="btn btn-ghost" onclick="this.closest('dialog').close()">{{ __('Cancel') }}</button>
            <button class="btn btn-primary">{{ __('Create') }}</button>
        </div>
    </form>
</dialog>

<dialog class="modal" id="rename-dialog">
    <form method="POST" id="rename-form">
        @csrf @method('PUT')
        <div class="card-header"><h2>{{ __('Rename category') }}</h2></div>
        <div class="card-body field">
            <label class="label" for="rename-name">{{ __('Name') }}</label>
            <input class="input" id="rename-name" name="name" required>
        </div>
        <div class="card-footer">
            <button type="button" class="btn btn-ghost" onclick="this.closest('dialog').close()">{{ __('Cancel') }}</button>
            <button class="btn btn-primary">{{ __('Save') }}</button>
        </div>
    </form>
</dialog>
@endsection

@push('scripts')
<script>
    document.querySelectorAll('[data-rename]').forEach((button) => button.addEventListener('click', () => {
        document.getElementById('rename-form').action = button.dataset.rename;
        document.getElementById('rename-name').value = button.dataset.name;
        document.getElementById('rename-dialog').showModal();
    }));
</script>
@endpush
