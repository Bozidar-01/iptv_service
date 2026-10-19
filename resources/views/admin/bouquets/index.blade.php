@extends('layouts.admin')

@section('title', __('Bouquets'))

@section('content')
<div class="page-header">
    <div>
        <h1>{{ __('Bouquets') }}</h1>
        <p>{{ __('Channel packages. Give a user one or more bouquets to decide what they can watch.') }}</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('admin.bouquets.create') }}" class="btn btn-primary"><x-icon name="plus" /> {{ __('New bouquet') }}</a>
    </div>
</div>

<div class="card">
    @if($bouquets->isEmpty())
        <div class="empty">
            <div class="empty-icon"><x-icon name="package" /></div>
            <h3>{{ __('No bouquets yet') }}</h3>
            <p>{{ __('Create one, or let a source create one when it imports.') }}</p>
            <a href="{{ route('admin.bouquets.create') }}" class="btn btn-primary"><x-icon name="plus" /> {{ __('New bouquet') }}</a>
        </div>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>{{ __('Name') }}</th><th class="right">{{ __('Channels') }}</th><th class="right">{{ __('Users') }}</th><th></th></tr></thead>
                <tbody>
                @foreach($bouquets as $bouquet)
                    <tr>
                        <td><div class="cell-flex"><span class="item-icon tone-blue"><x-icon name="package" /></span><a href="{{ route('admin.bouquets.edit', $bouquet) }}" class="cell-main">{{ $bouquet->name }}</a></div></td>
                        <td class="right">
                            <a href="{{ route('admin.channels.index', ['bouquet' => $bouquet->id]) }}">{{ number_format($bouquet->channels_count) }}</a>
                            @if($bouquet->channels_count !== $bouquet->active_channels_count)
                                <div class="cell-sub">{{ __(':count enabled', ['count' => number_format($bouquet->active_channels_count)]) }}</div>
                            @endif
                        </td>
                        <td class="right">{{ number_format($bouquet->users_count) }}</td>
                        <td class="shrink">
                            <div class="actions">
                                <a href="{{ route('admin.channels.index', ['bouquet' => $bouquet->id]) }}" class="btn btn-ghost btn-sm">{{ __('Channels') }}</a>
                                <a href="{{ route('admin.bouquets.edit', $bouquet) }}" class="btn btn-ghost btn-icon" title="{{ __('Edit') }}" aria-label="{{ __('Edit :name', ['name' => $bouquet->name]) }}"><x-icon name="edit" /></a>
                                <form method="POST" action="{{ route('admin.bouquets.destroy', $bouquet) }}" class="inline-form"
                                      data-confirm="{{ __('Delete :name? Users lose access to its channels. The channels themselves are kept.', ['name' => $bouquet->name]) }}">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-danger-ghost btn-icon" title="{{ __('Delete') }}" aria-label="{{ __('Delete :name', ['name' => $bouquet->name]) }}"><x-icon name="trash" /></button>
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
