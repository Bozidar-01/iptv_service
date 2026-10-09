@extends('layouts.admin')

@section('title', __('Import users'))

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route('admin.users.index') }}" class="breadcrumb"><x-icon name="arrow-left" /> {{ __('Users') }}</a>
        <h1>{{ __('Import users') }}</h1>
        <p>{{ __('Create many users from a CSV file (Excel or Google Sheets can save one). The first row has the column names.') }}</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('admin.users.import.template') }}" class="btn btn-secondary"><x-icon name="copy" /> {{ __('Download an example') }}</a>
    </div>
</div>

<div class="stack">
    @if(session('import'))
        @php($result = session('import'))
        <div class="card">
            <div class="card-header">
                <div>
                    <h2>{{ $result['checked'] ? __('Check result: nothing was created') : __('Import result') }}</h2>
                    <p>
                        {{ $result['checked'] ? trans_choice(':count user would be created|:count users would be created', count($result['created']), ['count' => count($result['created'])]) : trans_choice(':count user created|:count users created', count($result['created']), ['count' => count($result['created'])]) }}
                        @if($result['credits'] > 0) · {{ trans_choice(':count credit|:count credits', $result['credits'], ['count' => $result['credits']]) }} @endif
                        @if($result['errors']) · {{ trans_choice(':count row skipped|:count rows skipped', count($result['errors']), ['count' => count($result['errors'])]) }} @endif
                    </p>
                </div>
            </div>
            @if($result['errors'])
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th class="shrink">{{ __('Row') }}</th><th>{{ __('Username') }}</th><th>{{ __('Problem') }}</th></tr></thead>
                        <tbody>
                        @foreach($result['errors'] as $error)
                            <tr><td>{{ $error['line'] }}</td><td>{{ $error['username'] }}</td><td>{{ $error['message'] }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if($result['created'] && !$result['checked'])
                <div class="card-body">
                    <label class="label" for="created-logins">{{ __('Logins of the new users') }}</label>
                    <div class="copy-field" style="align-items: flex-start">
                        <textarea class="textarea mono" id="created-logins" rows="{{ min(12, count($result['created']) + 1) }}" readonly onclick="this.select()">{{ collect($result['created'])->map(fn ($c) => $c['username'].','.$c['password'])->prepend('username,password')->join("\n") }}</textarea>
                        <button type="button" class="btn btn-secondary" data-copy="#created-logins"><x-icon name="copy" /> <span data-copy-label>{{ __('Copy') }}</span></button>
                    </div>
                    <p class="help">{{ __('Passwords that were left empty were made up. You can also see them on each user\'s page.') }}</p>
                </div>
            @endif
        </div>
    @endif

    <form method="POST" action="{{ route('admin.users.import.store') }}" enctype="multipart/form-data" class="card">
        @csrf
        <div class="card-header"><h2>{{ __('Upload a file') }}</h2></div>
        <div class="card-body stack" style="gap: 16px">
            @include('admin.partials.errors')
            <div class="field">
                <label class="label" for="file">{{ __('CSV file') }}</label>
                <input class="input" id="file" name="file" type="file" accept=".csv,text/csv,text/plain" required>
            </div>
            <label class="check">
                <input type="checkbox" name="check_only" value="1" checked>
                <div><strong>{{ __('Only check the file') }}</strong><span>{{ __('Shows what would happen and any problems, and creates nothing. Untick to create the users.') }}</span></div>
            </label>
            <div>
                <div class="label" style="margin-bottom: 6px">{{ __('Columns') }}</div>
                <ul class="steps">
                    <li><code>username</code> {{ __('(required, unique)') }}</li>
                    <li><code>password</code> {{ __('(at least 6 characters; empty = made up)') }}</li>
                    <li><code>expiration_date</code> {{ __('(like 2027-01-31; required if you have credits)') }}</li>
                    <li><code>max_connections</code> {{ __('(streams at once; empty = 1)') }}</li>
                    <li><code>bouquets</code> {{ __('(names separated by |, for example Sports|Movies)') }}</li>
                    <li><code>email</code>, <code>status</code> {{ __('(active or disabled; empty = active)') }}</li>
                </ul>
            </div>
        </div>
        <div class="card-footer">
            <button class="btn btn-primary"><x-icon name="upload" /> {{ __('Upload') }}</button>
        </div>
    </form>
</div>
@endsection
