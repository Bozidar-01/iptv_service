{{-- Plain-text part: {!! !!} because this is not HTML --}}
{!! config('app.name') !!}

@if($alerts->isEmpty())
{!! __('Email alerts are working') !!}

{!! __('This is a test from :app. When a channel or source you watch stops working, you will get an email like this one.', ['app' => config('app.name')]) !!}
@else
@foreach($groups as $type => $items)
{!! match ($type) {
    'system' => __('Background tasks'),
    'sync_failed' => trans_choice(':count source failed to import|:count sources failed to import', count($items), ['count' => count($items)]),
    'down' => trans_choice(':count channel stopped working|:count channels stopped working', count($items), ['count' => count($items)]),
    default => trans_choice(':count channel is working again|:count channels are working again', count($items), ['count' => count($items)]),
} !!}
@foreach($items as $alert)
- {!! $alert->isSystem() ? $alert->message() : $alert->name !!}@if($alert->channel_id && $alert->source) ({!! $alert->source->name !!})@endif, {!! $alert->created_at->translatedFormat('j M, H:i') !!}@if($alert->reason()): {!! $alert->reason() !!}@endif
@endforeach

@endforeach
{!! __('Open alerts') !!}: {!! route('admin.alerts.index') !!}
@endif
