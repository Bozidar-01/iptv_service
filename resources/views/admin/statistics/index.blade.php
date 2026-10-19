@extends('layouts.admin')

@section('title', __('Statistics'))

@section('content')
<div class="page-header">
    <div>
        <h1>{{ __('Statistics') }}</h1>
        <p>{{ __('What your users watch. A play is one stream start; switching channel counts again. Times are :timezone.', ['timezone' => config('app.timezone')]) }}</p>
    </div>
    <div class="page-actions">
        <form method="GET" action="{{ route('admin.statistics.index') }}">
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
        <div class="stat-label"><x-icon name="play" /> {{ __('Plays') }}</div>
        <div class="stat-value">{{ number_format($report['plays']) }}</div>
        <div class="stat-meta">{{ __(':count a day on average', ['count' => number_format($report['plays'] / $report['days'], 1)]) }}</div>
    </div>
    <div class="card stat">
        <div class="stat-label"><x-icon name="users" /> {{ __('Viewers') }}</div>
        <div class="stat-value">{{ number_format($report['viewers']) }}</div>
        <div class="stat-meta">{{ __('users who watched something') }}</div>
    </div>
    <div class="card stat">
        <div class="stat-label"><x-icon name="tv" /> {{ __('Channels watched') }}</div>
        <div class="stat-value">{{ number_format($report['channels']) }}</div>
        <div class="stat-meta"><a href="{{ route('admin.channels.index', ['status' => 'unwatched']) }}">{{ trans_choice(':count listed channel nobody played|:count listed channels nobody played', $report['unwatched'], ['count' => number_format($report['unwatched'])]) }}</a></div>
    </div>
    <div class="card stat">
        <div class="stat-label"><x-icon name="signal" /> {{ __('Most at once') }}</div>
        <div class="stat-value">{{ number_format($report['peak']) }}</div>
        <div class="stat-meta">{{ $report['peak_at'] ? \Illuminate\Support\Carbon::parse($report['peak_at'])->translatedFormat('j M, H:i') : __('counted every 5 minutes') }}</div>
    </div>
</div>

@if($report['plays'] === 0)
    <div class="card">
        <div class="empty">
            <div class="empty-icon"><x-icon name="activity" /></div>
            <h3>{{ __('No plays in this period') }}</h3>
            <p>{{ __('Plays show up here once users start watching.') }}</p>
        </div>
    </div>
@else
    <div class="stack">
        <div class="card">
            <div class="card-header"><h2>{{ __('Plays per day') }}</h2></div>
            <div class="card-body">
                <div class="chart" role="img" aria-label="{{ __('Plays per day') }}">
                    @foreach($report['per_day'] as $day => $plays)
                        <div style="height: {{ max(1, round($plays / $busiestDay * 100)) }}%" title="{{ \Illuminate\Support\Carbon::parse($day)->translatedFormat('M j') }}: {{ trans_choice(':count play|:count plays', $plays, ['count' => $plays]) }}"></div>
                    @endforeach
                </div>
                <div class="chart-axis">
                    <span>{{ \Illuminate\Support\Carbon::parse($report['from'])->translatedFormat('M j') }}</span>
                    <span>{{ __('Busiest day: :count plays', ['count' => number_format($busiestDay)]) }}</span>
                    <span>{{ now()->translatedFormat('M j') }}</span>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>{{ __('Busiest hours') }}</h2></div>
            <div class="card-body">
                <div class="chart" role="img" aria-label="{{ __('Busiest hours') }}">
                    @foreach($report['per_hour'] as $hour => $plays)
                        <div style="height: {{ max(1, round($plays / $busiestHour * 100)) }}%" title="{{ sprintf('%02d:00', $hour) }}: {{ trans_choice(':count play|:count plays', $plays, ['count' => $plays]) }}"></div>
                    @endforeach
                </div>
                <div class="chart-axis"><span>00:00</span><span>12:00</span><span>23:00</span></div>
            </div>
        </div>

        @if($report['peak'] > 0)
            <div class="card">
                <div class="card-header"><div><h2>{{ __('Most streams at once per day') }}</h2><p>{{ __('Counted every 5 minutes: how many devices were playing. Compare with your providers\' connection limits and the receiver\'s tuners.') }}</p></div></div>
                <div class="card-body">
                    <div class="chart" role="img" aria-label="{{ __('Most streams at once per day') }}">
                        @foreach($report['peak_per_day'] as $day => $peak)
                            <div style="height: {{ max(1, round($peak / max(1, $report['peak']) * 100)) }}%" title="{{ \Illuminate\Support\Carbon::parse($day)->translatedFormat('M j') }}: {{ trans_choice(':count stream|:count streams', $peak, ['count' => $peak]) }}"></div>
                        @endforeach
                    </div>
                    <div class="chart-axis">
                        <span>{{ \Illuminate\Support\Carbon::parse($report['from'])->translatedFormat('M j') }}</span>
                        <span>{{ __('Most: :count', ['count' => number_format($report['peak'])]) }}</span>
                        <span>{{ now()->translatedFormat('M j') }}</span>
                    </div>
                </div>
            </div>
        @endif

        @if($report['sources'] !== [])
            <div class="card">
                <div class="card-header"><div><h2>{{ __('Per source') }}</h2><p>{{ __('Which providers people use: worth paying for, or not.') }}</p></div></div>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>{{ __('Source') }}</th><th class="right">{{ __('Plays') }}</th><th class="right hide-sm">{{ __('Viewers') }}</th><th class="right">{{ __('Most at once') }}</th></tr></thead>
                        <tbody>
                        @foreach($report['sources'] as $row)
                            <tr>
                                <td class="cell-main truncate">
                                    {{ $row['name'] ?? __('Added manually') }}@if($row['receiver']) <span class="badge">{{ __('Receiver') }}</span>@endif
                                    <div class="bar"><span style="width: {{ round($row['plays'] / max(1, $report['sources'][0]['plays']) * 100) }}%"></span></div>
                                </td>
                                <td class="right">{{ number_format($row['plays']) }}</td>
                                <td class="right muted hide-sm">{{ number_format($row['viewers']) }}</td>
                                <td class="right">{{ number_format($row['peak']) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div class="grid-2">
            <div class="card">
                <div class="card-header"><h2>{{ __('Most played channels') }}</h2></div>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>{{ __('Channel') }}</th><th class="right">{{ __('Plays') }}</th><th class="right hide-sm">{{ __('Viewers') }}</th></tr></thead>
                        <tbody>
                        @foreach($report['top_channels'] as $row)
                            <tr>
                                <td class="cell-main truncate">
                                    {{ $row['name'] ?? __('Deleted channel') }}
                                    <div class="bar"><span style="width: {{ round($row['plays'] / $report['top_channels'][0]['plays'] * 100) }}%"></span></div>
                                </td>
                                <td class="right">{{ number_format($row['plays']) }}</td>
                                <td class="right muted hide-sm">{{ number_format($row['viewers']) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2>{{ __('Most active users') }}</h2></div>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>{{ __('User') }}</th><th class="right">{{ __('Plays') }}</th><th class="right hide-sm">{{ __('Channels') }}</th></tr></thead>
                        <tbody>
                        @foreach($report['top_users'] as $row)
                            <tr>
                                <td class="cell-main truncate">
                                    {{ $row['username'] ?? __('Deleted user') }}
                                    <div class="bar"><span style="width: {{ round($row['plays'] / $report['top_users'][0]['plays'] * 100) }}%"></span></div>
                                </td>
                                <td class="right">{{ number_format($row['plays']) }}</td>
                                <td class="right muted hide-sm">{{ number_format($row['channels']) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endif
@endsection
