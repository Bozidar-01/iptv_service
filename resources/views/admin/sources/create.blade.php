@extends('layouts.admin')

@section('title', __('Add source'))
@section('container', 'container-narrow')

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route('admin.sources.index') }}" class="breadcrumb"><x-icon name="arrow-left" /> {{ __('Sources') }}</a>
        <h1>{{ __('Add source') }}</h1>
        <p>{{ __("Import channels from a provider's M3U playlist.") }}</p>
    </div>
</div>

<form method="POST" action="{{ route('admin.sources.store') }}" enctype="multipart/form-data">
    @csrf
    @include('admin.sources._form', ['submit' => __('Add and import')])
</form>
@endsection
