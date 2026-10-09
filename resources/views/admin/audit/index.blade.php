@extends('layouts.admin')

@section('title', __('Audit log'))

@section('content')
<div class="page-header">
    <div>
        <h1>{{ __('Audit log') }}</h1>
        <p>{{ __('Who changed what: users, credits, hidden channels and deletions, by admins and resellers. Kept for a year.') }}</p>
    </div>
</div>

<div class="card">
    <form method="GET" action="{{ route('admin.audit.index') }}" class="toolbar">
        <div class="input-group">
            <x-icon name="search" />
            <input class="input" type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="{{ __('Search by name…') }}">
        </div>
        <select class="select" name="actor" onchange="this.form.submit()" aria-label="{{ __('Who') }}">
            <option value="">{{ __('Everyone') }}</option>
            @foreach($actors as $actor)
                <option value="{{ $actor }}" @selected(($filters['actor'] ?? '') === $actor)>{{ $actor }}</option>
            @endforeach
        </select>
        <select class="select" name="action" onchange="this.form.submit()" aria-label="{{ __('What') }}">
            <option value="">{{ __('Any change') }}</option>
            @foreach($actions as $key => $label)
                <option value="{{ $key }}" @selected(($filters['action'] ?? '') === $key)>{{ $label }}</option>
            @endforeach
        </select>
        @if(array_filter($filters))
            <a href="{{ route('admin.audit.index') }}" class="btn btn-ghost btn-sm"><x-icon name="x" /> {{ __('Clear') }}</a>
        @endif
    </form>

    @if($entries->isEmpty())
        <div class="empty">
            <div class="empty-icon"><x-icon name="shield" /></div>
            <h3>{{ __('Nothing recorded yet') }}</h3>
            <p>{{ __('Changes made from now on show up here.') }}</p>
        </div>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Who') }}</th><th>{{ __('What') }}</th><th>{{ __('About') }}</th><th class="hide-sm">{{ __('Details') }}</th><th class="right">{{ __('When') }}</th></tr></thead>
                <tbody>
                @foreach($entries as $entry)
                    <tr>
                        <td class="cell-main">{{ $entry->actor_name }}</td>
                        <td>{{ $entry->label() }}</td>
                        <td>
                            @if($entry->subject_type === 'user' && in_array($entry->subject_id, $existingUsers, true))
                                <a href="{{ route('admin.users.show', $entry->subject_id) }}">{{ $entry->subject_name }}</a>
                            @else
                                {{ $entry->subject_name ?? '–' }}
                            @endif
                        </td>
                        <td class="hide-sm muted">{{ $entry->summary() }}</td>
                        <td class="right muted" title="{{ $entry->created_at }}">{{ $entry->created_at->translatedFormat('M j, H:i') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $entries->links('admin.partials.pagination') }}
    @endif
</div>
@endsection
