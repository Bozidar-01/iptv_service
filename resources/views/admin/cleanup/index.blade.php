@extends('layouts.admin')

@section('title', __('Cleanup rules'))

@php
    $kinds = [
        'channel_name' => __('Change channel names'),
        'category' => __('Rename or merge categories'),
        'channel_category' => __('Put channels in a category by name'),
    ];
    $matches = ['contains' => __('contains'), 'equals' => __('is exactly'), 'regex' => __('matches (regex)')];
    $packInfo = [
        'tags' => [__('Remove tags in square brackets'), __('"24h HD Canarias [301]" becomes "24h HD Canarias".')],
        'radio' => [__('Radio channels'), __('Removes "(RADIO)" from names and puts them in a Radio category.')],
        'satellite' => [__('Satellite group names'), __('Replaces groups like "(TV) 11222 H 30000 3/4 2025 - 2060" with one category called Satellite.')],
        'genres' => [__('Genres by channel name'), __('Sorts the channels in the Satellite category into Sports, News, Kids, Movies, Music and Documentary, from words in the channel name (Sky Sport, CNN, Disney, Cinema…). Add "Satellite group names" too, or it has nothing to sort.')],
    ];
@endphp

@section('content')
<div class="page-header">
    <div>
        <h1>{{ __('Cleanup rules') }}</h1>
        <p>{{ __('Tidy channel names and categories the same way on every import, so apps show clear groups. Rules apply to the next import; press Apply now to change the channels you already have.') }}</p>
    </div>
    <div class="page-actions">
        <form method="POST" action="{{ route('admin.cleanup.apply') }}" class="inline-form" data-confirm="{{ __('Apply the enabled rules to all channels now? Names and categories change.') }}">
            @csrf
            <button class="btn btn-primary"><x-icon name="refresh" /> {{ __('Apply now') }}</button>
        </form>
    </div>
</div>

<div class="stack">
    @if(session('preview'))
        @php($preview = session('preview'))
        <div class="alert alert-info">
            <x-icon name="eye" />
            <div class="alert-body">
                <strong>{{ __('Preview: this rule would change :names names and :categories categories.', ['names' => number_format($preview['names']), 'categories' => number_format($preview['categories'])]) }}</strong>
                @if($preview['examples'])
                    <ul>
                        @foreach($preview['examples'] as [$before, $after])
                            <li>{{ $before }} → {{ $after }}</li>
                        @endforeach
                    </ul>
                @endif
                <div>{{ __('Nothing is saved yet. Check the examples, then press Save rule.') }}</div>
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-header">
            <div>
                <h2>{{ __('Ready-made rules') }}</h2>
                <p>{{ __('Add a set in one click, then change or delete single rules below.') }}</p>
            </div>
        </div>
        <ul class="list">
            @foreach($packs as $pack)
                <li>
                    <div>
                        <strong>{{ $packInfo[$pack][0] }}</strong>
                        <div class="muted">{{ $packInfo[$pack][1] }}</div>
                    </div>
                    <form method="POST" action="{{ route('admin.cleanup.pack', $pack) }}" class="inline-form">
                        @csrf
                        <button class="btn btn-secondary btn-sm"><x-icon name="plus" /> {{ __('Add') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
    </div>

    <div class="card">
        <div class="card-header"><div><h2>{{ __('Your rules') }}</h2><p>{{ __('They run in this order. Disabled rules are skipped.') }}</p></div></div>
        @if($rules->isEmpty())
            <div class="empty" style="padding: 28px 20px"><p>{{ __('No rules yet. Add a ready-made set above or your own below.') }}</p></div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('What') }}</th><th>{{ __('When it') }}</th><th>{{ __('Becomes') }}</th><th class="hide-sm">{{ __('Source') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach($rules as $rule)
                        <tr @style(['opacity: 0.55' => !$rule->enabled])>
                            <td>{{ $kinds[$rule->kind] }}</td>
                            <td><span class="muted">{{ $matches[$rule->match] }}</span> <code>{{ $rule->pattern }}</code>@if($rule->when_category)<div class="cell-sub">{{ __('only in :category', ['category' => $rule->when_category]) }}</div>@endif</td>
                            <td>@if($rule->replacement !== null && $rule->replacement !== '')<code>{{ $rule->replacement }}</code>@else<span class="faint">{{ __('removed') }}</span>@endif</td>
                            <td class="hide-sm">{{ $rule->source?->name ?? __('All sources') }}</td>
                            <td class="shrink">
                                <div class="actions">
                                    <form method="POST" action="{{ route('admin.cleanup.toggle', $rule) }}" class="inline-form">
                                        @csrf
                                        <button class="btn btn-ghost btn-sm">{{ $rule->enabled ? __('Disable') : __('Enable') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.cleanup.destroy', $rule) }}" class="inline-form" data-confirm="{{ __('Delete this rule?') }}">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-danger-ghost btn-icon" title="{{ __('Delete') }}" aria-label="{{ __('Delete rule') }}"><x-icon name="trash" /></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <form method="POST" action="{{ route('admin.cleanup.store') }}" class="card">
        @csrf
        <div class="card-header"><h2>{{ __('Add a rule') }}</h2></div>
        <div class="card-body stack" style="gap: 16px">
            @include('admin.partials.errors')
            <div class="form-grid">
                <div class="field">
                    <label class="label" for="kind">{{ __('What should it change?') }}</label>
                    <select class="select" id="kind" name="kind">
                        @foreach($kinds as $value => $label)
                            <option value="{{ $value }}" @selected(old('kind') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label class="label" for="source_id">{{ __('Source') }}</label>
                    <select class="select" id="source_id" name="source_id">
                        <option value="">{{ __('All sources') }}</option>
                        @foreach($sources as $source)
                            <option value="{{ $source->id }}" @selected((string) old('source_id') === (string) $source->id)>{{ $source->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label class="label" for="match">{{ __('When the name') }}</label>
                    <select class="select" id="match" name="match">
                        @foreach($matches as $value => $label)
                            <option value="{{ $value }}" @selected(old('match') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label class="label" for="pattern">{{ __('Text') }}</label>
                    <input class="input mono" id="pattern" name="pattern" value="{{ old('pattern') }}" required maxlength="255">
                </div>
                <div class="field full">
                    <label class="label" for="when_category">{{ __('Only for channels in this category (optional)') }}</label>
                    <input class="input" id="when_category" name="when_category" value="{{ old('when_category') }}" maxlength="255">
                    <span class="help">{{ __('Used by "Put channels in a category by name". Leave empty to apply to every channel.') }}</span>
                </div>
                <div class="field full">
                    <label class="label" for="replacement">{{ __('It becomes') }}</label>
                    <input class="input mono" id="replacement" name="replacement" value="{{ old('replacement') }}" maxlength="255">
                    <span class="help">{{ __('For channel names, leave this empty to remove the text. For categories, this is the category name channels go to.') }}</span>
                </div>
            </div>
        </div>
        <div class="card-footer">
            <button class="btn btn-secondary" name="preview" value="1"><x-icon name="eye" /> {{ __('Preview') }}</button>
            <button class="btn btn-primary"><x-icon name="plus" /> {{ __('Save rule') }}</button>
        </div>
    </form>
</div>
@endsection
