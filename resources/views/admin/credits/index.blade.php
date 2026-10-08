@extends('layouts.admin')

@section('title', __('Credits'))

@section('content')
<div class="page-header">
    <div>
        <h1>{{ __('Credits') }}</h1>
        <p>{{ __('1 credit = 1 month of subscription for one user. Creating a user or extending their end date uses credits.') }}</p>
    </div>
    @if(Auth::user()->isAdmin())
        <div class="page-actions">
            <form method="GET" action="{{ route('admin.credits.index') }}">
                <select class="select" name="reseller" onchange="this.form.submit()" aria-label="{{ __('Reseller') }}">
                    <option value="">{{ __('All resellers') }}</option>
                    @foreach($resellers as $option)
                        <option value="{{ $option->id }}" @selected($reseller && $reseller->id === $option->id)>{{ $option->username }}</option>
                    @endforeach
                </select>
            </form>
        </div>
    @endif
</div>

<div class="stats">
    @if($reseller)
        <div class="card stat">
            <div class="stat-label"><x-icon name="package" /> {{ $reseller->isReseller() && Auth::id() === $reseller->id ? __('Your credits') : __('Credits of :name', ['name' => $reseller->username]) }}</div>
            <div class="stat-value">{{ $reseller->usesCredits() ? number_format($reseller->credits) : __('Unlimited') }}</div>
            <div class="stat-meta">{{ $reseller->usesCredits() ? __('months of subscription left to give') : __('not limited by credits') }}</div>
        </div>
    @else
        @foreach($resellers as $option)
            <div class="card stat">
                <div class="stat-label"><x-icon name="users" /> <a href="{{ route('admin.users.show', $option) }}">{{ $option->username }}</a></div>
                <div class="stat-value">{{ $option->usesCredits() ? number_format($option->credits) : __('Unlimited') }}</div>
                <div class="stat-meta"><a href="{{ route('admin.credits.index', ['reseller' => $option->id]) }}">{{ __('History') }}</a></div>
            </div>
        @endforeach
    @endif
</div>

<div class="card">
    <div class="card-header"><h2>{{ __('History') }}</h2></div>
    @if($transactions->isEmpty())
        <div class="empty">
            <div class="empty-icon"><x-icon name="package" /></div>
            <h3>{{ __('No credit changes yet') }}</h3>
            <p>{{ __('Credits given to a reseller and credits they use show up here.') }}</p>
        </div>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>{{ __('When') }}</th>
                    @unless($reseller)<th>{{ __('Reseller') }}</th>@endunless
                    <th>{{ __('What') }}</th>
                    <th class="right">{{ __('Credits') }}</th>
                    <th class="right hide-sm">{{ __('Balance') }}</th>
                    <th class="hide-sm">{{ __('Note') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($transactions as $transaction)
                    <tr>
                        <td class="muted" title="{{ $transaction->created_at }}">{{ $transaction->created_at->translatedFormat('M j, H:i') }}</td>
                        @unless($reseller)<td class="cell-main">{{ $transaction->user->username ?? __('Deleted user') }}</td>@endunless
                        <td>
                            {{ $transaction->label() }}
                            @if($transaction->customer)
                                · <a href="{{ route('admin.users.show', $transaction->customer) }}">{{ $transaction->customer->username }}</a>
                            @endif
                            @if($transaction->actor && $transaction->actor_id !== $transaction->user_id)
                                <div class="cell-sub">{{ __('by :name', ['name' => $transaction->actor->username]) }}</div>
                            @endif
                        </td>
                        <td class="right">
                            @if($transaction->amount > 0)
                                <span class="badge badge-success">+{{ number_format($transaction->amount) }}</span>
                            @elseif($transaction->amount < 0)
                                <span class="badge badge-warning">{{ number_format($transaction->amount) }}</span>
                            @else
                                <span class="faint">–</span>
                            @endif
                        </td>
                        <td class="right muted hide-sm">{{ $transaction->balance_after === null ? __('Unlimited') : number_format($transaction->balance_after) }}</td>
                        <td class="hide-sm muted truncate" style="max-width: 240px" title="{{ $transaction->note }}">{{ $transaction->note }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $transactions->links('admin.partials.pagination') }}
    @endif
</div>
@endsection
