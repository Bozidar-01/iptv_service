@extends('layouts.admin')

@section('title', 'Edit bouquet')
@section('container', 'container-narrow')

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route('admin.bouquets.index') }}" class="breadcrumb"><x-icon name="arrow-left" /> Bouquets</a>
        <h1>{{ $bouquet->name }}</h1>
        <p>{{ number_format($bouquet->channels_count) }} channels · {{ number_format($bouquet->users_count) }} users</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('admin.channels.index', ['bouquet' => $bouquet->id]) }}" class="btn btn-secondary"><x-icon name="tv" /> Manage channels</a>
    </div>
</div>

<form method="POST" action="{{ route('admin.bouquets.update', $bouquet) }}">
    @csrf
    @method('PUT')
    @include('admin.bouquets._form', ['submit' => 'Save changes'])
</form>
@endsection
