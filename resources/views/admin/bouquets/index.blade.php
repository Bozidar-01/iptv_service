@extends('layouts.admin')

@section('title', 'Bouquets')

@section('content')
<div class="page-header">
    <div>
        <h1>Bouquets</h1>
        <p>Channel packages. Give a user one or more bouquets to decide what they can watch.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('admin.bouquets.create') }}" class="btn btn-primary"><x-icon name="plus" /> New bouquet</a>
    </div>
</div>

<div class="card">
    @if($bouquets->isEmpty())
        <div class="empty">
            <div class="empty-icon"><x-icon name="package" /></div>
            <h3>No bouquets yet</h3>
            <p>Create one, or let a source create one when it imports.</p>
            <a href="{{ route('admin.bouquets.create') }}" class="btn btn-primary"><x-icon name="plus" /> New bouquet</a>
        </div>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Name</th><th class="right">Channels</th><th class="right">Users</th><th></th></tr></thead>
                <tbody>
                @foreach($bouquets as $bouquet)
                    <tr>
                        <td class="cell-main">{{ $bouquet->name }}</td>
                        <td class="right">
                            <a href="{{ route('admin.channels.index', ['bouquet' => $bouquet->id]) }}">{{ number_format($bouquet->channels_count) }}</a>
                            @if($bouquet->channels_count !== $bouquet->active_channels_count)
                                <div class="cell-sub">{{ number_format($bouquet->active_channels_count) }} enabled</div>
                            @endif
                        </td>
                        <td class="right">{{ number_format($bouquet->users_count) }}</td>
                        <td class="shrink">
                            <div class="actions">
                                <a href="{{ route('admin.channels.index', ['bouquet' => $bouquet->id]) }}" class="btn btn-ghost btn-sm">Channels</a>
                                <a href="{{ route('admin.bouquets.edit', $bouquet) }}" class="btn btn-ghost btn-icon" title="Edit" aria-label="Edit {{ $bouquet->name }}"><x-icon name="edit" /></a>
                                <form method="POST" action="{{ route('admin.bouquets.destroy', $bouquet) }}" class="inline-form"
                                      data-confirm="Delete {{ $bouquet->name }}? Users lose access to its channels. The channels themselves are kept.">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-danger-ghost btn-icon" title="Delete" aria-label="Delete {{ $bouquet->name }}"><x-icon name="trash" /></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
