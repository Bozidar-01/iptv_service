@extends('layouts.admin')

@section('title', __('Add channel'))
@section('container', 'container-narrow')

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route('admin.channels.index') }}" class="breadcrumb"><x-icon name="arrow-left" /> {{ __('Channels') }}</a>
        <h1>{{ __('Add channel') }}</h1>
        <p>{!! __('Add a single stream by hand. To import many at once, :link.', ['link' => '<a href="'.e(route('admin.sources.create')).'">'.e(__('add a source')).'</a>']) !!}</p>
    </div>
</div>

<form method="POST" action="{{ route('admin.channels.store') }}">
    @csrf
    @include('admin.channels._form', ['submit' => __('Add channel')])
</form>
@endsection
