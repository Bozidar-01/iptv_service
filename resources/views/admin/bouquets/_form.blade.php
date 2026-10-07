<div class="card">
    <div class="card-body form-grid">
        <div class="field full">
            <label class="label" for="name">Name</label>
            <input class="input" id="name" name="name" value="{{ old('name', $bouquet->name ?? '') }}" required placeholder="e.g. Sports pack">
        </div>

        <div class="field full">
            <span class="label">{{ isset($bouquet) ? 'Add more channels by category' : 'Channels' }} <span class="optional">(optional)</span></span>
            <span class="help">Every channel in the selected categories is added. To fine-tune individual channels, use bulk actions on the <a href="{{ route('admin.channels.index', isset($bouquet) ? ['bouquet' => $bouquet->id] : []) }}">Channels</a> page.</span>
            @if($categories->isEmpty())
                <p class="faint">No categories with channels yet. Import a source first.</p>
            @else
                <div class="input-group" style="max-width: 320px">
                    <x-icon name="search" />
                    <input class="input" type="search" placeholder="Filter categories…" oninput="filterTiles(this.value)">
                </div>
                <div class="check-list" id="category-tiles">
                    @foreach($categories as $category)
                        <label class="check-tile" data-name="{{ mb_strtolower($category->name) }}">
                            <input type="checkbox" name="categories[]" value="{{ $category->id }}" @checked(in_array($category->id, old('categories', [])))>
                            <span class="name" title="{{ $category->name }}">{{ $category->name }}</span>
                            <span class="faint">{{ number_format($category->channels_count) }}</span>
                        </label>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
    <div class="card-footer">
        <a href="{{ route('admin.bouquets.index') }}" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-primary">{{ $submit }}</button>
    </div>
</div>

@push('scripts')
<script>
    function filterTiles(term) {
        term = term.toLowerCase();
        document.querySelectorAll('#category-tiles .check-tile').forEach((tile) => tile.hidden = !tile.dataset.name.includes(term));
    }
</script>
@endpush
