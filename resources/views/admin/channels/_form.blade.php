@if(isset($channel) && $channel->source)
    <div class="alert alert-info">
        <x-icon name="alert" />
        <div class="alert-body">
            {!! __('Imported from :source. The next sync overwrites the name, stream URL, logo and category; enabling or disabling the channel is kept.', ['source' => '<strong>'.e($channel->source->name).'</strong>']) !!}
        </div>
    </div>
@endif

<div class="card">
    <div class="card-body form-grid">
        <div class="field full">
            <label class="label" for="name">{{ __('Name') }}</label>
            <input class="input" id="name" name="name" value="{{ old('name', $channel->name ?? '') }}" required>
        </div>

        <div class="field full">
            <label class="label" for="stream_url">{{ __('Stream URL') }}</label>
            <input class="input mono" id="stream_url" name="stream_url" type="url" value="{{ old('stream_url', $channel->stream_url ?? '') }}" required placeholder="http://server.example/live/stream.m3u8">
            <span class="help">{{ __('The upstream address. Users never see it in their playlist; they get a link through this server.') }}</span>
        </div>

        <div class="field">
            <label class="label" for="category_id">{{ __('Category') }} <span class="optional">{{ __('(optional)') }}</span></label>
            <select class="select" id="category_id" name="category_id">
                <option value="">{{ __('None') }}</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}" @selected(old('category_id', $channel->category_id ?? '') == $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label class="label" for="logo_url">{{ __('Logo URL') }} <span class="optional">{{ __('(optional)') }}</span></label>
            <input class="input" id="logo_url" name="logo_url" type="url" value="{{ old('logo_url', $channel->logo_url ?? '') }}" placeholder="https://…/logo.png">
        </div>

        <div class="field full">
            <input type="hidden" name="is_active" value="0">
            <label class="check">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $channel->is_active ?? true))>
                <div><strong>{{ __('Enabled') }}</strong><span>{{ __("Disabled channels are hidden from playlists and can't be played.") }}</span></div>
            </label>
        </div>
    </div>
    <div class="card-footer">
        <a href="{{ route('admin.channels.index') }}" class="btn btn-ghost">{{ __('Cancel') }}</a>
        <button type="submit" class="btn btn-primary">{{ $submit }}</button>
    </div>
</div>
