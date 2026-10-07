@extends('layouts.admin')

@section('title', 'Edit channel')
@section('container', 'container-narrow')

@section('content')
<div class="page-header">
    <div>
        <a href="{{ route('admin.channels.index') }}" class="breadcrumb"><x-icon name="arrow-left" /> Channels</a>
        <h1>{{ $channel->name }}</h1>
        <p>In {{ trans_choice('{0} no bouquets|{1} 1 bouquet|[2,*] :count bouquets', $channel->bouquets()->count()) }}.</p>
    </div>
</div>

<form method="POST" action="{{ route('admin.channels.update', $channel) }}">
    @csrf
    @method('PUT')
    @include('admin.channels._form', ['submit' => 'Save changes'])
</form>
@endsection
