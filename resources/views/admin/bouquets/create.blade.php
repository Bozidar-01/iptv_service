@extends('layouts.admin')

@section('title', __('New bouquet'))
@section('container', 'container-narrow')

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route('admin.bouquets.index') }}" class="breadcrumb"><x-icon name="arrow-left" /> {{ __('Bouquets') }}</a>
        <h1>{{ __('New bouquet') }}</h1>
        <p>{{ __('A package of channels you can assign to users.') }}</p>
    </div>
</div>

<form method="POST" action="{{ route('admin.bouquets.store') }}">
    @csrf
    @include('admin.bouquets._form', ['submit' => __('Create bouquet')])
</form>
@endsection
