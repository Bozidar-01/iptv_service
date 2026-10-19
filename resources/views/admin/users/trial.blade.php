@extends('layouts.admin')

@section('title', __('New trial'))
@section('container', 'container-narrow')

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route('admin.users.index') }}" class="breadcrumb"><x-icon name="arrow-left" /> {{ __('Users') }}</a>
        <h1>{{ __('New trial') }}</h1>
        <p>{{ __('A short test account for someone who wants to try before they buy. It gets a random username and password, one device, and costs no credits.') }}</p>
    </div>
</div>

<form method="POST" action="{{ route('admin.users.trial.store') }}" class="card">
    @csrf
    <div class="card-body stack" style="gap: 20px">
        @include('admin.partials.errors')

        <div class="field">
            <label class="label" for="hours">{{ __('How long') }}</label>
            <select class="select" id="hours" name="hours" style="max-width: 240px">
                @foreach(\App\Http\Controllers\Admin\TrialController::HOURS as $hours)
                    <option value="{{ $hours }}" @selected((int) old('hours', 24) === $hours)>{{ $hours === 24 ? __('24 hours') : trans_choice(':count day|:count days', $hours / 24, ['count' => $hours / 24]) }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <div class="label">{{ __('Bouquets') }}</div>
            @if($bouquets->isEmpty())
                <p class="muted">{{ __('You have no bouquets yet.') }}</p>
            @else
                <div class="check-list">
                    @foreach($bouquets as $bouquet)
                        <label class="check-tile">
                            <input type="checkbox" name="bouquets[]" value="{{ $bouquet->id }}" @checked(in_array($bouquet->id, old('bouquets', [])))>
                            <span class="name">{{ $bouquet->name }}</span>
                            <span class="faint">{{ trans_choice(':count channel|:count channels', $bouquet->channels_count, ['count' => number_format($bouquet->channels_count)]) }}</span>
                        </label>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
    <div class="card-footer">
        <a href="{{ route('admin.users.index') }}" class="btn btn-ghost">{{ __('Cancel') }}</a>
        <button class="btn btn-primary"><x-icon name="plus" /> {{ __('Create trial') }}</button>
    </div>
</form>
@endsection
