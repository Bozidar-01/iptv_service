@extends('layouts.admin')

@section('title', 'Add source')
@section('container', 'container-narrow')

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route('admin.sources.index') }}" class="breadcrumb"><x-icon name="arrow-left" /> Sources</a>
        <h1>Add source</h1>
        <p>Import channels from a provider's M3U playlist.</p>
    </div>
</div>

<form method="POST" action="{{ route('admin.sources.store') }}" enctype="multipart/form-data">
    @csrf
    @include('admin.sources._form', ['submit' => 'Add and import'])
</form>
@endsection
