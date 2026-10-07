@extends('layouts.admin')

@section('title', 'Categories')

@section('content')
<div class="page-header">
    <div>
        <h1>Categories</h1>
        <p>Groups shown in the TV app (e.g. Sports, News). Imported playlists create them automatically.</p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-primary" onclick="document.getElementById('create-dialog').showModal()"><x-icon name="plus" /> New category</button>
    </div>
</div>

<div class="card">
    @if($categories->isEmpty())
        <div class="empty">
            <div class="empty-icon"><x-icon name="folder" /></div>
            <h3>No categories yet</h3>
            <p>They're created when you import a source, or you can add one yourself.</p>
        </div>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Name</th><th class="right">Channels</th><th class="right hide-sm">Enabled</th><th></th></tr></thead>
                <tbody>
                @foreach($categories as $category)
                    <tr>
                        <td class="cell-main">{{ $category->name }}</td>
                        <td class="right"><a href="{{ route('admin.channels.index', ['category' => $category->id]) }}">{{ number_format($category->channels_count) }}</a></td>
                        <td class="right hide-sm muted">{{ number_format($category->active_channels_count) }}</td>
                        <td class="shrink">
                            <div class="actions">
                                <a href="{{ route('admin.channels.index', ['category' => $category->id]) }}" class="btn btn-ghost btn-sm">View channels</a>
                                <button type="button" class="btn btn-ghost btn-icon" title="Rename" aria-label="Rename {{ $category->name }}"
                                        data-rename="{{ route('admin.categories.update', $category) }}" data-name="{{ $category->name }}"><x-icon name="edit" /></button>
                                <form method="POST" action="{{ route('admin.categories.destroy', $category) }}" class="inline-form"
                                      data-confirm="Delete {{ $category->name }}? Its {{ $category->channels_count }} channels are kept without a category.">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-danger-ghost btn-icon" title="Delete" aria-label="Delete {{ $category->name }}"><x-icon name="trash" /></button>
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
        <div class="card-header"><h2>New category</h2></div>
        <div class="card-body field">
            <label class="label" for="create-name">Name</label>
            <input class="input" id="create-name" name="name" required>
        </div>
        <div class="card-footer">
            <button type="button" class="btn btn-ghost" onclick="this.closest('dialog').close()">Cancel</button>
            <button class="btn btn-primary">Create</button>
        </div>
    </form>
</dialog>

<dialog class="modal" id="rename-dialog">
    <form method="POST" id="rename-form">
        @csrf @method('PUT')
        <div class="card-header"><h2>Rename category</h2></div>
        <div class="card-body field">
            <label class="label" for="rename-name">Name</label>
            <input class="input" id="rename-name" name="name" required>
        </div>
        <div class="card-footer">
            <button type="button" class="btn btn-ghost" onclick="this.closest('dialog').close()">Cancel</button>
            <button class="btn btn-primary">Save</button>
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
