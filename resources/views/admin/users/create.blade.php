@extends('layouts.admin')

@section('title', __('New user'))
@section('container', 'container-narrow')

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route('admin.users.index') }}" class="breadcrumb"><x-icon name="arrow-left" /> {{ __('Users') }}</a>
        <h1>{{ __('New user') }}</h1>
        <p>{{ __("After saving you'll get the playlist link to put in the TV app.") }}</p>
    </div>
</div>

<form method="POST" action="{{ route('admin.users.store') }}" class="stack">
    @csrf
    @include('admin.users._form', ['submit' => __('Create user')])
</form>
@endsection
