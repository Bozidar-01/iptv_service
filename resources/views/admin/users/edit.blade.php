@extends('layouts.admin')

@section('title', __('Edit user'))
@section('container', 'container-narrow')

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route('admin.users.show', $user) }}" class="breadcrumb"><x-icon name="arrow-left" /> {{ $user->username }}</a>
        <h1>{{ __('Edit user') }}</h1>
    </div>
</div>

<form method="POST" action="{{ route('admin.users.update', $user) }}" class="stack">
    @csrf
    @method('PUT')
    @include('admin.users._form', ['submit' => __('Save changes')])
</form>
@endsection
