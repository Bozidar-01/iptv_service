@extends('layouts.admin')

@section('title', 'Content Groups')

@section('content')
<header class="view-header">
    <div>
        <h2 class="view-title">Content<br>Groups</h2>
        <p class="view-subtitle">Hierarchical mapping for broadcast categorization</p>
    </div>
    <div style="display: flex; gap: 16px;">
        <button onclick="openModal('createCategoryModal')" class="action-btn btn-electric shadow-hard">
            <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Initialize Group
        </button>
    </div>
</header>

@include('admin.partials.errors')

@if(session('success'))
    <div class="canvas-card fade-in" style="margin-bottom: 40px; padding: 20px; border-color: rgba(74, 222, 128, 0.3); background: rgba(74, 222, 128, 0.05);">
        <div style="display: flex; align-items: center; gap: 12px; color: var(--success); font-weight: 700; font-size: 0.9rem;">
            <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            {{ session('success') }}
        </div>
    </div>
@endif

<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 32px;">
    @forelse($categories as $category)
        <div class="canvas-card fade-in" style="display: flex; flex-direction: column; gap: 24px; padding: 32px; border-top: 4px solid var(--accent);">
            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                <div style="width: 52px; height: 52px; background: var(--accent-dim); border-radius: 14px; display: flex; align-items: center; justify-content: center; color: var(--accent); border: 1px solid rgba(34, 211, 238, 0.2);">
                    <svg style="width: 28px; height: 28px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                </div>
                <div style="display: flex; gap: 8px;">
                    <button onclick="editCategory({{ $category->id }}, '{{ $category->name }}')" class="btn-outline action-btn" style="padding: 10px; border-radius: 10px; border-color: rgba(34, 211, 238, 0.2); color: var(--accent);">
                        <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    </button>
                    <form action="{{ route('admin.categories.destroy', $category) }}" method="POST" onsubmit="return confirm('WARNING: Permanent deletion of content group: {{ $category->name }}?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="action-btn" style="padding: 10px; border-radius: 10px; background: rgba(248, 113, 113, 0.05); border: 1px solid rgba(248, 113, 113, 0.2); color: var(--danger);">
                            <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                    </form>
                </div>
            </div>
            <div>
                <h3 style="font-size: 1.75rem; font-weight: 800; letter-spacing: -0.04em;">{{ $category->name }}</h3>
                <p style="font-size: 0.75rem; color: var(--text-dim); font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; margin-top: 6px;">MAPPER ID: #{{ str_pad($category->id, 4, '0', STR_PAD_LEFT) }}</p>
            </div>
            <div style="border-top: 1px solid var(--border); padding-top: 20px; display: flex; justify-content: space-between; align-items: center;">
                 <span style="font-size: 0.7rem; font-weight: 800; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.1em;">Asset Hub</span>
                 <span style="height: 10px; width: 10px; border-radius: 50%; background: var(--success); box-shadow: 0 0 10px var(--success);"></span>
            </div>
        </div>
    @empty
        <div style="grid-column: 1 / -1; text-align: center; padding: 100px; border: 1px dashed var(--border); border-radius: var(--radius-lg); color: var(--text-dim);">
            No content groups discovered in the manifest.
        </div>
    @endforelse
</div>

<!-- Modal Overlays -->
<div id="createCategoryModal" style="position: fixed; inset: 0; background: rgba(0,0,0,0.85); backdrop-filter: blur(8px); display: none; align-items: center; justify-content: center; z-index: 1000;">
    <div class="canvas-card fade-in" style="width: 100%; max-width: 500px; padding: 48px;">
        <h3 style="font-size: 1.75rem; font-weight: 900; margin-bottom: 8px;">New Group</h3>
        <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 40px;">Define a new protocol grouping for signal streams.</p>
        <form action="{{ route('admin.categories.store') }}" method="POST">
            @csrf
            <div style="margin-bottom: 32px;">
                <label class="input-label">Human-Readable Title</label>
                <input type="text" name="name" required class="input-field" placeholder="e.g. Sports Ultra HD">
            </div>
            <div style="display: flex; gap: 16px;">
                <button type="button" onclick="closeModal('createCategoryModal')" class="action-btn btn-outline" style="flex: 1;">Dismiss</button>
                <button type="submit" class="action-btn btn-electric" style="flex: 2;">Initialize Node</button>
            </div>
        </form>
    </div>
</div>

<div id="editCategoryModal" style="position: fixed; inset: 0; background: rgba(0,0,0,0.85); backdrop-filter: blur(8px); display: none; align-items: center; justify-content: center; z-index: 1000;">
    <div class="canvas-card fade-in" style="width: 100%; max-width: 500px; padding: 48px;">
        <h3 style="font-size: 1.75rem; font-weight: 900; margin-bottom: 8px;">Edit Group</h3>
        <p style="color: var(--text-muted); font-size: 0.95rem; margin-bottom: 40px;">Modify the structural metadata for this content node.</p>
        <form id="editForm" method="POST">
            @csrf
            @method('PUT')
            <div style="margin-bottom: 32px;">
                <label class="input-label">Human-Readable Title</label>
                <input type="text" id="editName" name="name" required class="input-field">
            </div>
            <div style="display: flex; gap: 16px;">
                <button type="button" onclick="closeModal('editCategoryModal')" class="action-btn btn-outline" style="flex: 1;">Cancel</button>
                <button type="submit" class="action-btn btn-electric" style="flex: 2;">Sync Configuration</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal(id) { document.getElementById(id).style.display = 'flex'; }
    function closeModal(id) { document.getElementById(id).style.display = 'none'; }
    function editCategory(id, name) {
        const form = document.getElementById('editForm');
        const input = document.getElementById('editName');
        form.action = `/admin/categories/${id}`;
        input.value = name;
        openModal('editCategoryModal');
    }
</script>
@endsection
