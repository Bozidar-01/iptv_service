@extends('layouts.admin')

@section('title', __('Backups'))

@section('content')
<div class="page-header">
    <div>
        <h1>{{ __('Backups') }}</h1>
        <p>{{ __('The database is backed up every night. Check that the newest backup is complete and can be read, on every disk it is stored on.') }}</p>
    </div>
    <div class="page-actions">
        <form method="POST" action="{{ route('admin.backups.verify') }}" class="inline-form">
            @csrf
            <button class="btn btn-primary"><x-icon name="check-circle" /> {{ __('Check the newest backup') }}</button>
        </form>
    </div>
</div>

<div class="stats">
    <div class="card stat">
        <div class="stat-label"><x-icon name="clock" /> {{ __('Last backup') }}</div>
        <div class="stat-value" style="font-size: 22px">{{ $lastBackup ? $lastBackup->diffForHumans() : __('None yet') }}</div>
        <div class="stat-meta">
            @if($lastBackup && $lastBackup->diffInHours(now()) >= $staleHours)
                <span class="badge badge-warning">{{ __('older than :hours hours', ['hours' => $staleHours]) }}</span>
            @else
                {{ $lastBackup?->translatedFormat('j M Y, H:i') }}
            @endif
        </div>
    </div>
    <div class="card stat">
        <div class="stat-label"><x-icon name="shield" /> {{ __('Last check') }}</div>
        <div class="stat-value" style="font-size: 22px">{{ $lastVerified ? $lastVerified->diffForHumans() : __('Never') }}</div>
        <div class="stat-meta">{{ __('a passed check of the newest backup') }}</div>
    </div>
</div>

<div class="stack">
    @if(session('backup_check'))
        @foreach(session('backup_check') as $result)
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2>{{ __('Check of :disk', ['disk' => $result['disk']]) }}</h2>
                        <p>{{ $result['file'] ?? __('No backup') }}</p>
                    </div>
                    <span class="badge {{ $result['ok'] ? 'badge-success' : 'badge-danger' }} badge-dot">{{ $result['ok'] ? __('Passed') : __('Problem found') }}</span>
                </div>
                <ul class="list">
                    @foreach($result['checks'] as $check)
                        <li>
                            <div>
                                <strong>{{ $check['label'] }}</strong>
                                @if($check['detail'])<div class="muted">{{ $check['detail'] }}</div>@endif
                            </div>
                            <span class="badge {{ $check['ok'] ? 'badge-success' : 'badge-danger' }} badge-dot">{{ $check['ok'] ? __('OK') : __('Failed') }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
        <p class="help">{{ __('This reads the whole file and compares its tables with the database. The best proof is still a restore into an empty database, see the documentation.') }}</p>
    @endif

    @foreach($disks as $disk)
        <div class="card">
            <div class="card-header">
                <div>
                    <h2>{{ $disk['main'] ? __('Backups on :disk', ['disk' => $disk['name']]) : __('Off-site copy on :disk', ['disk' => $disk['name']]) }}</h2>
                    <p>{{ __('The ten newest. Older ones are removed after :days days.', ['days' => config('iptv.backup.keep_days')]) }}</p>
                </div>
            </div>
            @if($disk['error'])
                <div class="alert alert-danger" style="margin: 16px 20px"><x-icon name="alert" /><div class="alert-body">{{ $disk['error'] }}</div></div>
            @elseif($disk['files'] === [])
                <div class="empty" style="padding: 28px 20px"><p>{{ __('No backups here yet.') }}</p></div>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>{{ __('File') }}</th><th class="right">{{ __('Size') }}</th><th class="right">{{ __('Written') }}</th></tr></thead>
                        <tbody>
                        @foreach($disk['files'] as $file)
                            <tr>
                                <td class="mono">{{ $file['name'] }}</td>
                                <td class="right">{{ number_format($file['size'] / 1048576, 1) }} MB</td>
                                <td class="right muted" title="{{ \Illuminate\Support\Carbon::createFromTimestamp($file['at']) }}">{{ \Illuminate\Support\Carbon::createFromTimestamp($file['at'])->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endforeach

    @unless(config('iptv.backup.copy_disk'))
        <div class="alert alert-warning">
            <x-icon name="alert" />
            <div class="alert-body">{{ __('There is no off-site copy: if the server is lost, the backups are lost with it. Set BACKUP_COPY_DISK, see the documentation.') }}</div>
        </div>
    @endunless
</div>
@endsection
