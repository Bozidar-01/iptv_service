{{-- One StreamAlert row, used by the header menu, the alerts page and the dashboard --}}
<li class="alert-item {{ $alert->read_at ? '' : 'unread' }}">
    <span class="alert-dot {{ $alert->isProblem() ? 'is-problem' : 'is-ok' }}"></span>
    <div style="min-width: 0; flex: 1">
        <div class="cell-main truncate">
            @if($alert->channel_id)
                <a href="{{ route('admin.channels.edit', $alert->channel_id) }}">{{ $alert->message() }}</a>
            @elseif($alert->source_id)
                <a href="{{ route('admin.sources.edit', $alert->source_id) }}">{{ $alert->message() }}</a>
            @else
                {{ $alert->message() }}
            @endif
        </div>
        <div class="cell-sub" title="{{ $alert->created_at }}">{{ $alert->created_at->diffForHumans() }}</div>
    </div>
</li>
