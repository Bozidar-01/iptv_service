@extends('layouts.admin')

@section('title', 'New user')
@section('container', 'container-narrow')

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route('admin.users.index') }}" class="breadcrumb"><x-icon name="arrow-left" /> Users</a>
        <h1>New user</h1>
        <p>After saving you'll get the playlist link to put in the TV app.</p>
    </div>
</div>

<form method="POST" action="{{ route('admin.users.store') }}" class="stack">
    @csrf
    @include('admin.users._form', ['submit' => 'Create user'])
</form>
@endsection
