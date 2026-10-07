@extends('layouts.admin')

@section('title', 'Add channel')
@section('container', 'container-narrow')

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route('admin.channels.index') }}" class="breadcrumb"><x-icon name="arrow-left" /> Channels</a>
        <h1>Add channel</h1>
        <p>Add a single stream by hand. To import many at once, <a href="{{ route('admin.sources.create') }}">add a source</a>.</p>
    </div>
</div>

<form method="POST" action="{{ route('admin.channels.store') }}">
    @csrf
    @include('admin.channels._form', ['submit' => 'Add channel'])
</form>
@endsection
