@extends('layouts.admin')

@section('title', __('Edit channel'))
@section('container', 'container-narrow')

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route('admin.channels.index') }}" class="breadcrumb"><x-icon name="arrow-left" /> {{ __('Channels') }}</a>
        <h1>{{ $channel->name }}</h1>
        <p>{{ trans_choice('{0} In no bouquets.|{1} In 1 bouquet.|[2,*] In :count bouquets.', $channel->bouquets()->count()) }}</p>
    </div>
</div>

<form method="POST" action="{{ route('admin.channels.update', $channel) }}">
    @csrf
    @method('PUT')
    @include('admin.channels._form', ['submit' => __('Save changes')])
</form>
@endsection
