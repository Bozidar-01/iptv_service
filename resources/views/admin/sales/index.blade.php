@extends('layouts.admin')

@section('title', __('Sales'))

@section('content')
<div class="page-header">
    <div>
        <h1>{{ __('Sales') }}</h1>
        <p>{{ __('What each reseller sold. 1 credit used = 1 month of subscription for one user. Resellers without a credit balance have no sales figures, only their users.') }}</p>
    </div>
    <div class="page-actions">
        <form method="GET" action="{{ route('admin.sales.index') }}">
            <select class="select" name="days" onchange="this.form.submit()" aria-label="{{ __('Period') }}">
                @foreach($periods as $period)
                    <option value="{{ $period }}" @selected($report['days'] === $period)>{{ __('Last :days days', ['days' => $period]) }}</option>
                @endforeach
            </select>
        </form>
    </div>
</div>

<div class="stats">
    <div class="card stat">
        <div class="stat-label"><x-icon name="package" /> {{ __('Months sold') }}</div>
        <div class="stat-value">{{ number_format($report['totals']['used']) }}</div>
        <div class="stat-meta">{{ trans_choice(':count credit given to resellers|:count credits given to resellers', $report['totals']['given'], ['count' => number_format($report['totals']['given'])]) }}</div>
    </div>
    <div class="card stat">
        <div class="stat-label"><x-icon name="users" /> {{ __('New users') }}</div>
        <div class="stat-value">{{ number_format($report['totals']['new']) }}</div>
        <div class="stat-meta">{{ __(':count active users now', ['count' => number_format($report['totals']['active'])]) }}</div>
    </div>
    <div class="card stat">
        <div class="stat-label"><x-icon name="clock" /> {{ __('Expiring in 30 days') }}</div>
        <div class="stat-value">{{ number_format($report['totals']['expiring']) }}</div>
        <div class="stat-meta">{{ __('to renew soon') }}</div>
    </div>
    <div class="card stat">
        <div class="stat-label"><x-icon name="alert" /> {{ __('Expired in the last 30 days') }}</div>
        <div class="stat-value">{{ number_format($report['totals']['expired']) }}</div>
        <div class="stat-meta">{{ __('not renewed yet') }}</div>
    </div>
</div>

<div class="stack">
    <div class="card">
        <div class="card-header"><h2>{{ __('Months sold per month') }}</h2></div>
        <div class="card-body">
            <div class="chart" role="img" aria-label="{{ __('Months sold per month') }}">
                @foreach($report['months'] as $month => $credits)
                    <div style="height: {{ max(1, round($credits / $busiestMonth * 100)) }}%" title="{{ \Illuminate\Support\Carbon::parse($month.'-01')->translatedFormat('F Y') }}: {{ number_format($credits) }}"></div>
                @endforeach
            </div>
            <div class="chart-axis">
                <span>{{ \Illuminate\Support\Carbon::parse(array_key_first($report['months']).'-01')->translatedFormat('M Y') }}</span>
                <span>{{ __('Best month: :count', ['count' => number_format($busiestMonth === 1 && max($report['months']) === 0 ? 0 : $busiestMonth)]) }}</span>
                <span>{{ \Illuminate\Support\Carbon::parse(array_key_last($report['months']).'-01')->translatedFormat('M Y') }}</span>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><div><h2>{{ __('By reseller') }}</h2><p>{{ __('For the last :days days, except the balance and the users.', ['days' => $report['days']]) }}</p></div></div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>{{ __('Reseller') }}</th>
                    <th class="right">{{ __('Months sold') }}</th>
                    <th class="right hide-sm">{{ __('Credits given') }}</th>
                    <th class="right">{{ __('Balance') }}</th>
                    <th class="right hide-sm">{{ __('Users') }}</th>
                    <th class="right hide-sm">{{ __('New') }}</th>
                    <th class="right">{{ __('Expiring') }}</th>
                    <th class="right hide-sm">{{ __('Expired') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($report['rows'] as $row)
                    <tr>
                        <td class="cell-main">
                            @if($row['id'])
                                <a href="{{ route('admin.users.show', $row['id']) }}">{{ $row['name'] }}</a> <a href="{{ route('admin.statements.index', ['reseller' => $row['id']]) }}" class="faint" style="font-size: 12px">{{ __('Statement') }}</a>
                            @else
                                {{ __('Your own users') }}
                            @endif
                        </td>
                        <td class="right">{{ number_format($row['used']) }}</td>
                        <td class="right hide-sm">{{ number_format($row['given']) }}</td>
                        <td class="right">
                            @if($row['id'] === null)
                                <span class="faint">–</span>
                            @elseif($row['credits'] === null)
                                <span class="muted">{{ __('Unlimited') }}</span>
                            @else
                                {{ number_format($row['credits']) }}
                                @if($row['days_left'] !== null)
                                    <div class="cell-sub"><span class="badge {{ $row['days_left'] < $lowDays ? 'badge-warning' : 'badge-success' }}">{{ trans_choice('about :count day left|about :count days left', $row['days_left'], ['count' => $row['days_left']]) }}</span></div>
                                @endif
                            @endif
                        </td>
                        <td class="right hide-sm">{{ number_format($row['active']) }} <span class="faint">/ {{ number_format($row['total']) }}</span></td>
                        <td class="right hide-sm">{{ number_format($row['new']) }}</td>
                        <td class="right">{{ number_format($row['expiring']) }}</td>
                        <td class="right hide-sm">{{ number_format($row['expired']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
