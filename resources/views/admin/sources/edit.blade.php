@extends('layouts.admin')

@section('title', __('Edit source'))
@section('container', 'container-narrow')

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route('admin.sources.index') }}" class="breadcrumb"><x-icon name="arrow-left" /> {{ __('Sources') }}</a>
        <h1>{{ $source->name }}</h1>
        <p>
            @include('admin.partials.source-status', ['source' => $source])
            <span class="muted">· {{ trans_choice(':count channel|:count channels', $source->channel_count, ['count' => number_format($source->channel_count)]) }} · {{ __('last synced :time', ['time' => $source->last_synced_at?->diffForHumans() ?? __('never')]) }}</span>
        </p>
    </div>
    <div class="page-actions">
        <form method="POST" action="{{ route('admin.sources.sync', $source) }}" class="inline-form">
            @csrf
            <button class="btn btn-secondary" @disabled($source->isBusy())><x-icon name="refresh" /> {{ __('Sync now') }}</button>
        </form>
    </div>
</div>

@if($source->status === 'failed' && $source->last_error)
    <div class="alert alert-danger"><x-icon name="alert" /><div class="alert-body"><strong>{{ __('Last sync failed:') }}</strong> {{ $source->last_error }}</div></div>
@endif

<form method="POST" action="{{ route('admin.sources.update', $source) }}" enctype="multipart/form-data">
    @csrf
    @method('PUT')
    @include('admin.sources._form', ['submit' => __('Save changes')])
</form>
@endsection
