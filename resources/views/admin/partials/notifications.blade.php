{{-- Header bell with unread alerts; $alertMenu comes from the layout view composer (AppServiceProvider) --}}
<details class="notif-menu">
    <summary class="btn btn-icon notif-button {{ $alertMenu['unread'] > 0 ? 'has-unread' : '' }}" aria-label="{{ trans_choice(':count unread alert|:count unread alerts', $alertMenu['unread'], ['count' => $alertMenu['unread']]) }}" title="{{ __('Alerts') }}">
        <x-icon name="bell" />
        @if($alertMenu['unread'] > 0)
            <span class="notif-count">{{ $alertMenu['unread'] > 99 ? '99+' : $alertMenu['unread'] }}</span>
        @endif
    </summary>
    <div class="notif-panel">
        <div class="notif-head">
            <strong>{{ __('Alerts') }}</strong>
            @if($alertMenu['unread'] > 0)
                <form method="POST" action="{{ route('admin.alerts.read') }}" class="inline-form">
                    @csrf
                    <button class="btn btn-ghost btn-sm">{{ __('Mark all as read') }}</button>
                </form>
            @endif
        </div>
        @if($alertMenu['latest']->isEmpty())
            <div class="notif-empty">
                <span class="notif-empty-icon"><x-icon name="bell" /></span>
                <p>{{ __('No alerts yet') }}</p>
            </div>
        @else
            <ul class="list">
                @foreach($alertMenu['latest'] as $alert)
                    @include('admin.partials.alert-item', ['alert' => $alert])
                @endforeach
            </ul>
        @endif
        <a href="{{ route('admin.alerts.index') }}" class="notif-all">{{ __('See all alerts') }}</a>
    </div>
</details>
