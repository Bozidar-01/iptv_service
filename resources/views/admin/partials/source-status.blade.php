@php
    $map = [
        'pending' => ['', __('Not synced')],
        'queued' => ['badge-info', __('Queued')],
        'syncing' => ['badge-info', __('Syncing')],
        'success' => ['badge-success', __('Synced')],
        'failed' => ['badge-danger', __('Failed')],
    ];
    [$class, $label] = $map[$source->status] ?? ['', ucfirst($source->status)];
@endphp
<span class="badge {{ $class }} {{ $source->isBusy() ? '' : 'badge-dot' }}" @if($source->status === 'failed' && $source->last_error) title="{{ $source->last_error }}" @endif>
    @if($source->isBusy())<x-icon name="loader" class="spin" style="width:12px;height:12px" />@endif
    {{ $label }}
</span>
