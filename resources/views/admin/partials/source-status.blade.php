@php
    $map = [
        'pending' => ['', 'Not synced'],
        'queued' => ['badge-info', 'Queued'],
        'syncing' => ['badge-info', 'Syncing'],
        'success' => ['badge-success', 'Synced'],
        'failed' => ['badge-danger', 'Failed'],
    ];
    [$class, $label] = $map[$source->status] ?? ['', ucfirst($source->status)];
@endphp
<span class="badge {{ $class }} {{ $source->isBusy() ? '' : 'badge-dot' }}" @if($source->status === 'failed' && $source->last_error) title="{{ $source->last_error }}" @endif>
    @if($source->isBusy())<x-icon name="loader" class="spin" style="width:12px;height:12px" />@endif
    {{ $label }}
</span>
