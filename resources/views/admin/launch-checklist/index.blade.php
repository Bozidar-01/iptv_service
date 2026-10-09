@extends('layouts.admin')

@section('title', __('Launch checklist'))

@section('content')
<div class="page-header">
    <div>
        <h1>{{ __('Launch checklist') }}</h1>
        <p>{{ __('What to have in place before real viewers and resellers use the panel. Most items are checked by the app itself; tick the rest off by hand.') }}</p>
    </div>
    <div class="page-actions">
        <span class="badge {{ $progress['done'] === $progress['total'] ? 'badge-success' : 'badge-warning' }} badge-dot">{{ __(':done of :total done', ['done' => $progress['done'], 'total' => $progress['total']]) }}</span>
    </div>
</div>

<div class="stack">
    @foreach($sections as $section)
        <div class="card">
            <div class="card-header">
                <div><h2>{{ $section['title'] }}</h2></div>
            </div>
            <ul class="list">
                @foreach($section['items'] as $item)
                    <li id="{{ $item['key'] }}">
                        <div>
                            <strong>{{ $item['title'] }}</strong>
                            @if($item['optional'])
                                <span class="badge badge-info">{{ __('Optional') }}</span>
                            @endif
                            <div class="muted">{{ $item['help'] }}</div>
                        </div>
                        <div class="page-actions" style="align-items: center">
                            @if($item['done'])
                                <span class="badge badge-success badge-dot">{{ __('Done') }}</span>
                            @elseif($item['optional'])
                                <span class="badge badge-info badge-dot">{{ __('Not set') }}</span>
                            @elseif(!$item['manual'])
                                <span class="badge badge-warning badge-dot">{{ __('To do') }}</span>
                            @endif

                            @if(!$item['done'] && $item['route'])
                                <a href="{{ route($item['route']) }}" class="btn btn-ghost btn-sm">{{ __('Open') }}</a>
                            @endif

                            @if($item['manual'])
                                <form method="POST" class="inline-form" action="{{ route('admin.launch-checklist.toggle', $item['key']) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-ghost btn-sm">{{ $item['done'] ? __('Undo') : __('Mark as done') }}</button>
                                </form>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
</div>
@endsection
