{{-- One StreamAlert row, used by the header menu, the alerts page and the dashboard --}}
<li class="alert-item {{ $alert->read_at ? '' : 'unread' }}">
    <span class="alert-dot {{ $alert->isProblem() ? 'is-problem' : 'is-ok' }}"><x-icon :name="$alert->isProblem() ? 'alert' : 'check'" /></span>
    <div class="item-body">
        <div class="cell-main truncate">
            @if($alert->channel_id)
                <a href="{{ route('admin.channels.edit', $alert->channel_id) }}">{{ $alert->message() }}</a>
            @elseif($alert->user_id)
                <a href="{{ route('admin.users.show', $alert->user_id) }}">{{ $alert->message() }}</a>
            @elseif($alert->source_id)
                <a href="{{ route('admin.sources.edit', $alert->source_id) }}">{{ $alert->message() }}</a>
            @else
                {{ $alert->message() }}
            @endif
        </div>
        <div class="cell-sub" title="{{ $alert->created_at }}">{{ $alert->created_at->diffForHumans() }}</div>
    </div>
    @if(($renewable ?? false) && $alert->user_id && in_array($alert->type, \App\Models\StreamAlert::SUBSCRIPTION_TYPES, true))
        <form method="POST" action="{{ route('admin.users.renew', $alert->user_id) }}" class="inline-form">
            @csrf
            <input type="hidden" name="months" value="1">
            <button class="btn btn-secondary btn-sm"><x-icon name="refresh" /> {{ __('Renew 1 month') }}</button>
        </form>
    @endif
</li>
