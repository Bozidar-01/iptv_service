@extends('layouts.admin')

@section('title', __('Alerts'))

@section('content')
<div class="page-header">
    <div>
        <h1>{{ __('Alerts') }}</h1>
        <p>{{ __('Channels and sources with alerts turned on that stopped or started working.') }}</p>
    </div>
    @if($alerts->isNotEmpty())
        <div class="page-actions">
            <form method="POST" action="{{ route('admin.alerts.read') }}" class="inline-form">
                @csrf
                <button class="btn btn-secondary"><x-icon name="check" /> {{ __('Mark all as read') }}</button>
            </form>
        </div>
    @endif
</div>

<div class="card">
    @if($alerts->isEmpty())
        <div class="empty">
            <div class="empty-icon"><x-icon name="bell" /></div>
            <h3>{{ __('No alerts yet') }}</h3>
            <p>{{ __('Turn on alerts for a channel or a source, and you will see here when it stops working.') }}</p>
        </div>
    @else
        <ul class="list">
            @foreach($alerts as $alert)
                @include('admin.partials.alert-item', ['alert' => $alert])
            @endforeach
        </ul>
        {{ $alerts->links('admin.partials.pagination') }}
    @endif
</div>
@endsection
