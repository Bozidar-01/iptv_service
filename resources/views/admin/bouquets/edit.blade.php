@extends('layouts.admin')

@section('title', __('Edit bouquet'))
@section('container', 'container-narrow')

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route('admin.bouquets.index') }}" class="breadcrumb"><x-icon name="arrow-left" /> {{ __('Bouquets') }}</a>
        <h1>{{ $bouquet->name }}</h1>
        <p>{{ trans_choice(':count channel|:count channels', $bouquet->channels_count, ['count' => number_format($bouquet->channels_count)]) }} · {{ trans_choice(':count user|:count users', $bouquet->users_count, ['count' => number_format($bouquet->users_count)]) }}</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('admin.channels.index', ['bouquet' => $bouquet->id]) }}" class="btn btn-secondary"><x-icon name="tv" /> {{ __('Manage channels') }}</a>
    </div>
</div>

<form method="POST" action="{{ route('admin.bouquets.update', $bouquet) }}">
    @csrf
    @method('PUT')
    @include('admin.bouquets._form', ['submit' => __('Save changes')])
</form>
@endsection
