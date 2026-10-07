@php
    $type = old('type', $source->type ?? 'url');
    $bouquetChoice = old('bouquet_id', isset($source) ? ($source->bouquet_id ?? '') : 'new');
@endphp
<div class="card">
    <div class="card-body form-grid">
        <div class="field full">
            <label class="label" for="name">{{ __('Name') }}</label>
            <input class="input" id="name" name="name" value="{{ old('name', $source->name ?? '') }}" required placeholder="{{ __('e.g. My provider') }}">
            <span class="help">{{ __('Only you see this.') }}</span>
        </div>

        <div class="field full">
            <span class="label">{{ __('Playlist') }}</span>
            <div class="segmented" role="radiogroup">
                <label><input type="radio" name="type" value="url" @checked($type === 'url') data-type-toggle><span><x-icon name="globe" style="width:15px;height:15px" /> {{ __('From URL') }}</span></label>
                <label><input type="radio" name="type" value="file" @checked($type === 'file') data-type-toggle><span><x-icon name="upload" style="width:15px;height:15px" /> {{ __('Upload file') }}</span></label>
                <label><input type="radio" name="type" value="receiver" @checked($type === 'receiver') data-type-toggle><span><x-icon name="tv" style="width:15px;height:15px" /> {{ __('Satellite receiver') }}</span></label>
            </div>
        </div>

        <div class="field full" data-type="url" @if($type !== 'url') hidden @endif>
            <label class="label" for="url">{{ __('M3U URL') }}</label>
            <input class="input mono" id="url" name="url" type="url" value="{{ old('url', $source->url ?? '') }}" placeholder="http://provider.example/get.php?username=…&password=…&type=m3u_plus">
            <span class="help">{{ __('The playlist link your provider gave you.') }}</span>
        </div>

        <div class="field full" data-type="file" @if($type !== 'file') hidden @endif>
            <label class="label" for="file">{{ __('M3U file') }} @if(isset($source) && $source->file_path)<span class="optional">{{ __('(leave empty to keep the current file)') }}</span>@endif</label>
            <input class="input" id="file" name="file" type="file" accept=".m3u,.m3u8,.txt">
            <span class="help">{{ __(".m3u or .m3u8, up to 50 MB (also limited by PHP's upload_max_filesize).") }}</span>
        </div>

        <div class="field full" data-type="receiver" @if($type !== 'receiver') hidden @endif>
            <label class="label" for="relay_url">{{ __('Relay address') }}</label>
            <input class="input mono" id="relay_url" name="relay_url" type="url" value="{{ old('relay_url', ($source->type ?? null) === 'receiver' ? $source->url : '') }}" placeholder="http://nebotv-home.duckdns.org:28080">
            <span class="help">{!! __('The address of the NeboTV relay running on the receiver. Setup: :link.', ['link' => '<code>docs/features/receiver.md</code>']) !!}</span>
        </div>

        <div class="field full" data-type="receiver" @if($type !== 'receiver') hidden @endif>
            <label class="label" for="relay_secret">{{ __('Shared secret') }} @if(isset($source) && $source->relay_secret)<span class="optional">{{ __('(leave empty to keep the current one)') }}</span>@endif</label>
            <div class="copy-field">
                <input class="input mono" id="relay_secret" name="relay_secret" value="{{ old('relay_secret', isset($source) ? '' : ($newSecret ?? '')) }}" autocomplete="off" spellcheck="false">
                <button type="button" class="btn btn-secondary" data-copy="#relay_secret"><x-icon name="copy" /> <span data-copy-label>{{ __('Copy') }}</span></button>
            </div>
            <span class="help">{{ __('Put the same value as "secret" in /etc/nebotv-relay.conf on the receiver. Play links are signed with it, so only this panel can make working links.') }}</span>
        </div>

        <div class="field full" data-type="receiver" @if($type !== 'receiver') hidden @endif>
            <input type="hidden" name="transcode" value="0">
            <label class="check">
                <input type="checkbox" name="transcode" value="1" @checked(old('transcode', $source->transcode ?? false))>
                <div><strong>{{ __('Smaller streams (transcoding)') }}</strong><span>{{ __('The receiver converts channels to 720p at about 1 Mbps. Better for phones and slow connections; needs transcoding turned on in the receiver.') }}</span></div>
            </label>
        </div>

        <div class="field full">
            <label class="label" for="bouquet_id">{{ __('Add channels to bouquet') }}</label>
            <select class="select" id="bouquet_id" name="bouquet_id">
                @unless(isset($source))
                    <option value="new" @selected($bouquetChoice === 'new')>{{ __('Create a new bouquet named after this source') }}</option>
                @endunless
                <option value="" @selected($bouquetChoice === '' || $bouquetChoice === null)>{{ __("Don't add to a bouquet") }}</option>
                @foreach($bouquets as $bouquet)
                    <option value="{{ $bouquet->id }}" @selected((string) $bouquetChoice === (string) $bouquet->id)>{{ $bouquet->name }}</option>
                @endforeach
            </select>
            <span class="help">{{ __('Every imported channel is added to this bouquet, so users with it can watch them straight away.') }}</span>
        </div>

        <div class="field full" style="gap: 12px">
            <input type="hidden" name="skip_vod" value="0">
            <label class="check" data-type="url file" @if($type === 'receiver') hidden @endif>
                <input type="checkbox" name="skip_vod" value="1" @checked(old('skip_vod', $source->skip_vod ?? true))>
                <div><strong>{{ __('Live TV only') }}</strong><span>{{ __('Skip movies and series (VOD) entries. Recommended: provider lists can contain tens of thousands of them.') }}</span></div>
            </label>
            <input type="hidden" name="auto_sync" value="0">
            <label class="check">
                <input type="checkbox" name="auto_sync" value="1" @checked(old('auto_sync', $source->auto_sync ?? true))>
                <div><strong>{{ __('Refresh automatically') }}</strong><span>{{ __('Re-import once a day to pick up new channels and changed stream links.') }}</span></div>
            </label>
            <input type="hidden" name="check_streams" value="0">
            <label class="check" data-type="url file" @if($type === 'receiver') hidden @endif>
                <input type="checkbox" name="check_streams" value="1" @checked(old('check_streams', $source->check_streams ?? true))>
                <div><strong>{{ __('Check streams') }}</strong><span>{{ __('Disable channels whose stream stops working, and turn them back on when it works again. Turn this off for channels that only play in some countries, since the check runs from the server.') }}</span></div>
            </label>
            <input type="hidden" name="alert_on_down" value="0">
            <label class="check">
                <input type="checkbox" name="alert_on_down" value="1" @checked(old('alert_on_down', $source->alert_on_down ?? false))>
                <div><strong>{{ __('Send me alerts') }}</strong><span>{{ __('Get an alert in the panel and by email when an import fails or any of its channels stops working. Channels are checked every 15 minutes.') }}</span></div>
            </label>
        </div>
    </div>
    <div class="card-footer">
        <a href="{{ route('admin.sources.index') }}" class="btn btn-ghost">{{ __('Cancel') }}</a>
        <button type="submit" class="btn btn-primary">{{ $submit }}</button>
    </div>
</div>

@push('scripts')
<script>
    document.querySelectorAll('[data-type-toggle]').forEach((radio) => radio.addEventListener('change', () => {
        document.querySelectorAll('[data-type]').forEach((el) => el.hidden = !el.dataset.type.split(' ').includes(radio.value));
    }));
</script>
@endpush
