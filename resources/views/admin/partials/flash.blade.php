@if(session('success'))
    <div class="alert alert-success" role="status">
        <x-icon name="check-circle" />
        <div class="alert-body">{{ session('success') }}</div>
        <button type="button" class="alert-close" aria-label="{{ __('Dismiss') }}" onclick="this.parentElement.remove()"><x-icon name="x" /></button>
    </div>
@endif

@if(session('error'))
    <div class="alert alert-danger" role="alert">
        <x-icon name="alert" />
        <div class="alert-body">{{ session('error') }}</div>
        <button type="button" class="alert-close" aria-label="{{ __('Dismiss') }}" onclick="this.parentElement.remove()"><x-icon name="x" /></button>
    </div>
@endif

@include('admin.partials.errors')
