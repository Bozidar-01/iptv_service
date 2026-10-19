@extends('layouts.admin')

@section('title', __('Statement'))

@section('content')
<div class="page-header">
    <div>
        <h1>{{ __('Statement') }}</h1>
        <p>{{ __('A reseller\'s month: credits given and used, subscriptions sold and renewed, and their users. 1 credit used = 1 month of subscription for one user.') }}</p>
    </div>
    @if($statement)
        <div class="page-actions">
            <a href="{{ route('admin.statements.csv', ['reseller' => $reseller->id, 'month' => $statement['month']]) }}" class="btn btn-secondary"><x-icon name="copy" /> {{ __('Download CSV') }}</a>
            <button type="button" class="btn btn-secondary" onclick="window.print()"><x-icon name="eye" /> {{ __('Print') }}</button>
        </div>
    @endif
</div>

@if(!$statement)
    <div class="card"><div class="empty"><div class="empty-icon"><x-icon name="users" /></div><h3>{{ __('No resellers yet') }}</h3></div></div>
@else
    <form method="GET" action="{{ route('admin.statements.index') }}" class="toolbar" style="margin-bottom: 16px">
        @if(Auth::user()->isAdmin())
            <select class="select" name="reseller" onchange="this.form.submit()" aria-label="{{ __('Reseller') }}">
                @foreach($resellers as $option)
                    <option value="{{ $option->id }}" @selected($option->id === $reseller->id)>{{ $option->username }}</option>
                @endforeach
            </select>
        @endif
        <select class="select" name="month" onchange="this.form.submit()" aria-label="{{ __('Month') }}">
            @foreach($months as $m)
                <option value="{{ $m }}" @selected($m === $statement['month'])>{{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $m.'-01')->translatedFormat('F Y') }}</option>
            @endforeach
        </select>
    </form>

    <div class="stack">
        <div class="card">
            <div class="card-header"><div><h2>{{ $statement['reseller']['name'] }} · {{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $statement['month'].'-01')->translatedFormat('F Y') }}</h2><p>{{ \Illuminate\Support\Carbon::parse($statement['from'])->translatedFormat('j M') }} – {{ \Illuminate\Support\Carbon::parse($statement['to'])->translatedFormat('j M Y') }}</p></div></div>
            <div class="card-body">
                <div class="stats">
                    <div class="card stat"><div class="stat-label">{{ __('New subscriptions') }}</div><div class="stat-value">{{ number_format($statement['sales']['new_subscriptions']) }}</div><div class="stat-meta">{{ trans_choice(':count credit used|:count credits used', $statement['credits']['used_new'], ['count' => number_format($statement['credits']['used_new'])]) }}</div></div>
                    <div class="card stat"><div class="stat-label">{{ __('Renewals') }}</div><div class="stat-value">{{ number_format($statement['sales']['renewals']) }}</div><div class="stat-meta">{{ trans_choice(':count credit used|:count credits used', $statement['credits']['used_renewals'], ['count' => number_format($statement['credits']['used_renewals'])]) }}</div></div>
                    <div class="card stat"><div class="stat-label">{{ __('Users added') }}</div><div class="stat-value">{{ number_format($statement['users']['added']) }}</div><div class="stat-meta">{{ __(':count active now', ['count' => number_format($statement['users']['active_now'])]) }}</div></div>
                    <div class="card stat"><div class="stat-label">{{ __('Subscriptions ended') }}</div><div class="stat-value">{{ number_format($statement['users']['ended']) }}</div><div class="stat-meta">{{ __('of :count users', ['count' => number_format($statement['users']['total'])]) }}</div></div>
                </div>
                <dl class="dl" style="margin-top: 16px">
                    @if($statement['reseller']['unlimited'])
                        <dt>{{ __('Credits') }}</dt><dd>{{ __('Not limited by credits') }}</dd>
                    @else
                        <dt>{{ __('Opening balance') }}</dt><dd>{{ $statement['credits']['opening'] === null ? '–' : number_format($statement['credits']['opening']) }}</dd>
                        <dt>{{ __('Credits given') }}</dt><dd>{{ number_format($statement['credits']['given']) }}</dd>
                        <dt>{{ __('Credits taken back') }}</dt><dd>{{ number_format($statement['credits']['taken_back']) }}</dd>
                        <dt>{{ __('Used for new subscriptions') }}</dt><dd>{{ number_format($statement['credits']['used_new']) }}</dd>
                        <dt>{{ __('Used for renewals') }}</dt><dd>{{ number_format($statement['credits']['used_renewals']) }}</dd>
                        <dt>{{ __('Closing balance') }}</dt><dd><strong>{{ $statement['credits']['closing'] === null ? '–' : number_format($statement['credits']['closing']) }}</strong></dd>
                    @endif
                </dl>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>{{ __('Credit history of the month') }}</h2></div>
            @if($statement['lines'] === [])
                <div class="empty" style="padding: 28px 20px"><p>{{ __('No credits were given or used this month.') }}</p></div>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>{{ __('Date') }}</th><th>{{ __('What') }}</th><th>{{ __('User') }}</th><th class="right">{{ __('Credits') }}</th><th class="right hide-sm">{{ __('Balance') }}</th></tr></thead>
                        <tbody>
                        @foreach($statement['lines'] as $line)
                            <tr>
                                <td class="muted">{{ \Illuminate\Support\Carbon::parse($line['date'])->translatedFormat('j M, H:i') }}</td>
                                <td>{{ $line['type'] }}@if($line['note'])<div class="cell-sub">{{ $line['note'] }}</div>@endif</td>
                                <td>{{ $line['customer'] ?? '–' }}</td>
                                <td class="right">{{ $line['credits'] > 0 ? '+' : '' }}{{ number_format($line['credits']) }}</td>
                                <td class="right hide-sm muted">{{ $line['balance'] === null ? '–' : number_format($line['balance']) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endif
@endsection
