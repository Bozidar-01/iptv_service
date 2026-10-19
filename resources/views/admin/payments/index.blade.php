@extends('layouts.admin')

@section('title', __('Payments'))

@php($money = fn ($amount) => \App\Support\Money::format($amount))
@php($isAdmin = Auth::user()->isAdmin())

@section('content')
<div class="page-header">
    <div>
        <h1>{{ __('Payments') }}</h1>
        <p>{{ $isAdmin
            ? __('Who has paid for their months, and what resellers owe for credits. Every new subscription and renewal is listed here with its price.')
            : __('Who has paid you for their months. Every new subscription and renewal is listed here with your price.') }}</p>
    </div>
</div>

<div class="stats">
    <div class="card stat">
        <div class="stat-label">{{ __('Not paid yet') }}</div>
        <div class="stat-value">{{ $money($totals['unpaid_amount']) }}</div>
        <div class="stat-meta">{{ trans_choice(':count sale|:count sales', $totals['unpaid'], ['count' => number_format($totals['unpaid'])]) }}</div>
    </div>
    <div class="card stat">
        <div class="stat-label">{{ __('Sold this month') }}</div>
        <div class="stat-value">{{ $money($totals['sold_month']) }}</div>
    </div>
    <div class="card stat">
        <div class="stat-label">{{ __('Received this month') }}</div>
        <div class="stat-value">{{ $money($totals['received_month']) }}</div>
    </div>
    @unless($isAdmin)
        <div class="card stat">
            <div class="stat-label">{{ __('You owe for credits') }}</div>
            <div class="stat-value">{{ $money(max(0, $balances[Auth::id()]['owes'] ?? 0)) }}</div>
            <div class="stat-meta">{{ __(':paid paid of :charged', ['paid' => $money($balances[Auth::id()]['paid'] ?? 0), 'charged' => $money($balances[Auth::id()]['charged'] ?? 0)]) }}</div>
        </div>
    @endunless
</div>

<div class="stack">
    <div class="card">
        <form method="GET" action="{{ route('admin.payments.index') }}" class="toolbar">
            <div class="input-group">
                <x-icon name="search" />
                <input class="input" type="search" name="search" value="{{ $filters['search'] }}" placeholder="{{ __('Search users…') }}">
            </div>
            <select class="select" name="status" onchange="this.form.submit()" aria-label="{{ __('Status') }}">
                <option value="unpaid" @selected($filters['status'] === 'unpaid')>{{ __('Not paid') }}</option>
                <option value="paid" @selected($filters['status'] === 'paid')>{{ __('Paid') }}</option>
                <option value="all" @selected($filters['status'] === 'all')>{{ __('All') }}</option>
            </select>
            @if($isAdmin)
                <select class="select" name="seller" onchange="this.form.submit()" aria-label="{{ __('Seller') }}">
                    <option value="">{{ __('All sellers') }}</option>
                    <option value="own" @selected($filters['seller'] === 'own')>{{ __('Your own users') }}</option>
                    @foreach($resellers as $reseller)
                        <option value="{{ $reseller->id }}" @selected($filters['seller'] === (string) $reseller->id)>{{ $reseller->username }}</option>
                    @endforeach
                </select>
            @endif
        </form>
        @if($sales->isEmpty())
            <div class="empty compact"><p>{{ $filters['status'] === 'unpaid' ? __('Everybody has paid.') : __('No sales yet.') }}</p></div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Date') }}</th><th>{{ __('User') }}</th><th class="hide-sm">{{ __('What') }}</th>@if($isAdmin)<th class="hide-sm">{{ __('Seller') }}</th>@endif<th class="right">{{ __('Amount') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach($sales as $sale)
                        <tr>
                            <td class="muted">{{ $sale->created_at->translatedFormat('j M Y') }}</td>
                            <td class="cell-main">
                                @if($sale->customer_id)<a href="{{ route('admin.users.show', $sale->customer_id) }}">{{ $sale->customer_name }}</a>@else{{ $sale->customer_name }}@endif
                            </td>
                            <td class="hide-sm">{{ $sale->kind === \App\Models\Sale::NEW ? __('New subscription') : __('Renewal') }} · {{ trans_choice(':count month|:count months', $sale->months, ['count' => $sale->months]) }}</td>
                            @if($isAdmin)<td class="hide-sm">{{ $sale->owner->username ?? __('You') }}</td>@endif
                            <td class="right">{{ $sale->amount !== null ? $money($sale->amount) : __('No price') }}</td>
                            <td class="shrink">
                                <form method="POST" action="{{ route('admin.payments.paid', $sale) }}" class="inline-form">
                                    @csrf
                                    <input type="hidden" name="paid" value="{{ $sale->paid_at ? 0 : 1 }}">
                                    @if($sale->paid_at)
                                        <button class="btn btn-ghost btn-sm" title="{{ __('Paid on :date. Press to mark as not paid.', ['date' => $sale->paid_at->translatedFormat('j M Y')]) }}"><span class="badge badge-success badge-dot">{{ __('Paid') }}</span></button>
                                    @else
                                        <button class="btn btn-secondary btn-sm"><x-icon name="check" /> {{ __('Mark paid') }}</button>
                                    @endif
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $sales->links('admin.partials.pagination') }}
        @endif
    </div>

    @if($isAdmin && $resellers->isNotEmpty())
        <div class="card">
            <div class="card-header"><div><h2>{{ __('Resellers') }}</h2><p>{{ __('Credits given are charged at the reseller\'s price per credit at that moment. Record what they pay you here.') }}</p></div></div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Reseller') }}</th><th class="right hide-sm">{{ __('Charged') }}</th><th class="right hide-sm">{{ __('Paid') }}</th><th class="right">{{ __('Owes') }}</th><th>{{ __('Record a payment') }}</th></tr></thead>
                    <tbody>
                    @foreach($resellers as $reseller)
                        @php($b = $balances[$reseller->id])
                        <tr>
                            <td class="cell-main">{{ $reseller->username }}@if($b['last_paid'])<div class="cell-sub">{{ __('Last paid :date', ['date' => \Illuminate\Support\Carbon::parse($b['last_paid'])->translatedFormat('j M Y')]) }}</div>@endif</td>
                            <td class="right hide-sm">{{ $money($b['charged']) }}</td>
                            <td class="right hide-sm">{{ $money($b['paid']) }}</td>
                            <td class="right"><strong>{{ $money($b['owes']) }}</strong></td>
                            <td>
                                <form method="POST" action="{{ route('admin.payments.reseller', $reseller) }}" class="copy-field" style="max-width: 320px">
                                    @csrf
                                    <input class="input" type="number" name="amount" min="0.01" step="0.01" required placeholder="{{ __('Amount') }}" aria-label="{{ __('Amount paid by :name', ['name' => $reseller->username]) }}">
                                    <input class="input hide-sm" name="note" maxlength="255" placeholder="{{ __('Note') }}" aria-label="{{ __('Note') }}">
                                    <button class="btn btn-secondary btn-sm"><x-icon name="plus" /> {{ __('Add') }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if($payments->isNotEmpty())
        <div class="card">
            <div class="card-header"><h2>{{ $isAdmin ? __('Latest payments from resellers') : __('Your payments for credits') }}</h2></div>
            <ul class="list">
                @foreach($payments as $payment)
                    <li><span>{{ $payment->paid_on->translatedFormat('j M Y') }}@if($isAdmin) · {{ $payment->reseller->username ?? '–' }}@endif @if($payment->note)<span class="muted"> · {{ $payment->note }}</span>@endif</span><strong>{{ $money($payment->amount) }}</strong></li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.payments.prices') }}" class="card">
        @csrf
        <div class="card-header"><div><h2>{{ __('Prices') }}</h2><p>{{ __('Used for new sales; earlier sales keep their amount.') }}</p></div></div>
        <div class="card-body form-grid">
            @if($isAdmin)
                <div class="field">
                    <label class="label" for="currency">{{ __('Currency') }}</label>
                    <input class="input" id="currency" name="currency" maxlength="5" required value="{{ old('currency', $settings['currency']) }}">
                </div>
                <div class="field">
                    <label class="label" for="price_per_month">{{ __('Your price per month') }} <span class="optional">{{ __('(optional)') }}</span></label>
                    <input class="input" id="price_per_month" name="price_per_month" type="number" min="0" step="0.01" value="{{ old('price_per_month', $settings['price_per_month']) }}">
                    <span class="help">{{ __('What your own users pay for one month.') }}</span>
                </div>
                <div class="field">
                    <label class="label" for="credit_price">{{ __('Price per credit for resellers') }} <span class="optional">{{ __('(optional)') }}</span></label>
                    <input class="input" id="credit_price" name="credit_price" type="number" min="0" step="0.01" value="{{ old('credit_price', $settings['credit_price']) }}">
                    <span class="help">{{ __('What a reseller pays you for one credit (one month for one user), unless they have their own price below.') }}</span>
                </div>
                @foreach($resellers as $reseller)
                    <div class="field">
                        <label class="label" for="credit_price_{{ $reseller->id }}">{{ __('Price per credit for :name', ['name' => $reseller->username]) }} <span class="optional">{{ __('(optional)') }}</span></label>
                        <input class="input" id="credit_price_{{ $reseller->id }}" name="credit_prices[{{ $reseller->id }}]" type="number" min="0" step="0.01" value="{{ $reseller->credit_price }}" placeholder="{{ $settings['credit_price'] !== null ? $money($settings['credit_price']) : '' }}">
                    </div>
                @endforeach
            @else
                <div class="field">
                    <label class="label" for="price_per_month">{{ __('Your price per month') }} <span class="optional">{{ __('(optional)') }}</span></label>
                    <input class="input" id="price_per_month" name="price_per_month" type="number" min="0" step="0.01" value="{{ old('price_per_month', Auth::user()->price_per_month) }}">
                    <span class="help">{{ __('What your users pay you for one month.') }}</span>
                </div>
            @endif
        </div>
        <div class="card-footer"><button class="btn btn-primary">{{ __('Save prices') }}</button></div>
    </form>
</div>
@endsection
