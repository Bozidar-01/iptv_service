@php
    $selected = old('bouquets', isset($user) ? $user->bouquets->pluck('id')->all() : []);
    $expiry = old('expiration_date', isset($user) ? $user->expiration_date?->toDateString() : now()->addMonth()->toDateString());
@endphp
<div class="card">
    <div class="card-header"><h2>{{ __('Account') }}</h2></div>
    <div class="card-body form-grid">
        <div class="field">
            <label class="label" for="username">{{ __('Username') }}</label>
            <input class="input" id="username" name="username" value="{{ old('username', $user->username ?? '') }}" required autocomplete="off">
        </div>
        <div class="field">
            <label class="label" for="password">{{ __('Password') }} @isset($user)<span class="optional">{{ __('(leave empty to keep)') }}</span>@endisset</label>
            <input class="input" id="password" name="password" type="text" @unless(isset($user)) required @endunless minlength="6" autocomplete="new-password">
            <span class="help">{{ __('At least 6 characters. Used for /m3u?username=…&password=… links.') }}</span>
        </div>
        <div class="field">
            <label class="label" for="email">{{ __('Email') }} <span class="optional">{{ __('(optional)') }}</span></label>
            <input class="input" id="email" name="email" type="email" value="{{ old('email', $user->email ?? '') }}" autocomplete="off">
            <span class="help">{{ __('For expiry reminders sent to the user. Leave empty to send none.') }}</span>
        </div>
        <div class="field">
            <label class="label" for="locale">{{ __('Language of emails') }}</label>
            <select class="select" id="locale" name="locale">
                <option value="">{{ __('Default') }}</option>
                @foreach(config('app.locales') as $code => $locale)
                    <option value="{{ $code }}" @selected(old('locale', $user->locale ?? '') === $code)>{{ $locale['name'] }}</option>
                @endforeach
            </select>
        </div>
        @if(Auth::user()->isAdmin())
            <div class="field">
                <label class="label" for="role">{{ __('Role') }}</label>
                <select class="select" id="role" name="role">
                    <option value="user" @selected(old('role', $user->role ?? 'user') === 'user')>{{ __('Subscriber: watches via playlist') }}</option>
                    <option value="reseller" @selected(old('role', $user->role ?? 'user') === 'reseller')>{{ __('Reseller: manages their own users in this panel') }}</option>
                    <option value="admin" @selected(old('role', $user->role ?? 'user') === 'admin')>{{ __('Admin: can sign in to this panel') }}</option>
                </select>
            </div>
            @if($resellers->isNotEmpty())
                <div class="field" data-role-panel="user">
                    <label class="label" for="owner_id">{{ __('Reseller') }} <span class="optional">{{ __('(optional)') }}</span></label>
                    <select class="select" id="owner_id" name="owner_id">
                        <option value="">{{ __('None: belongs to you') }}</option>
                        @foreach($resellers as $reseller)
                            <option value="{{ $reseller->id }}" @selected((int) old('owner_id', $user->owner_id ?? 0) === $reseller->id)>{{ $reseller->username }}</option>
                        @endforeach
                    </select>
                    <span class="help">{{ __('The reseller sees and manages this user.') }}</span>
                </div>
            @endif
            <div class="field" data-role-panel="reseller">
                <label class="label" for="max_customers">{{ __('Most users') }} <span class="optional">{{ __('(optional)') }}</span></label>
                <input class="input" id="max_customers" name="max_customers" type="number" min="1" value="{{ old('max_customers', $user->max_customers ?? '') }}">
                <span class="help">{{ __('How many users this reseller can create. Empty = no limit.') }}</span>
            </div>
            <div class="field" data-role-panel="reseller">
                <label class="label" for="max_customer_connections">{{ __('Most streams per user') }} <span class="optional">{{ __('(optional)') }}</span></label>
                <input class="input" id="max_customer_connections" name="max_customer_connections" type="number" min="1" max="100" value="{{ old('max_customer_connections', $user->max_customer_connections ?? '') }}">
                <span class="help">{{ __('The most simultaneous streams this reseller can give a user. Empty = no limit.') }}</span>
            </div>
        @endif
        <div class="field">
            <label class="label" for="status">{{ __('Status') }}</label>
            <select class="select" id="status" name="status">
                <option value="active" @selected(old('status', $user->status ?? 'active') === 'active')>{{ __('Active') }}</option>
                <option value="disabled" @selected(old('status', $user->status ?? 'active') === 'disabled')>{{ __('Disabled') }}</option>
            </select>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>{{ __('Subscription') }}</h2></div>
    <div class="card-body form-grid">
        <div class="field">
            <label class="label" for="expiration_date">{{ __('Expires on') }} <span class="optional">{{ __('(optional)') }}</span></label>
            <input class="input" id="expiration_date" name="expiration_date" type="date" value="{{ $expiry }}">
            <div style="display: flex; gap: 6px; flex-wrap: wrap">
                @foreach([1 => __('+1 month'), 3 => __('+3 months'), 12 => __('+1 year')] as $months => $label)
                    <button type="button" class="btn btn-ghost btn-sm" data-extend="{{ $months }}">{{ $label }}</button>
                @endforeach
                <button type="button" class="btn btn-ghost btn-sm" data-extend="0">{{ __('Never') }}</button>
            </div>
        </div>
        <div class="field">
            <label class="label" for="max_connections">{{ __('Simultaneous streams') }}</label>
            <input class="input" id="max_connections" name="max_connections" type="number" min="1" max="100" value="{{ old('max_connections', $user->max_connections ?? 1) }}" required>
            <span class="help">
                {{ __('How many different channels can play at the same time.') }}
                @if(Auth::user()->isReseller() && Auth::user()->max_customer_connections)
                    {{ __('At most :count for you.', ['count' => Auth::user()->max_customer_connections]) }}
                @endif
            </span>
        </div>

        <div class="field full">
            <span class="label">{{ __('Bouquets') }}</span>
            <span class="help">
                {{ Auth::user()->isAdmin() ? __('The channels this user can watch. For a reseller: the bouquets they may give to their users.') : __('The channels this user can watch.') }}
            </span>
            @if($bouquets->isEmpty())
                <p class="faint">{!! __('No bouquets yet. :link or add a source.', ['link' => '<a href="'.e(route('admin.bouquets.create')).'">'.e(__('Create one')).'</a>']) !!}</p>
            @else
                <div class="check-list">
                    @foreach($bouquets as $bouquet)
                        <label class="check-tile">
                            <input type="checkbox" name="bouquets[]" value="{{ $bouquet->id }}" @checked(in_array($bouquet->id, $selected))>
                            <span class="name">{{ $bouquet->name }}</span>
                            <span class="faint">{{ number_format($bouquet->channels_count) }}</span>
                        </label>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
    <div class="card-footer">
        <a href="{{ isset($user) ? route('admin.users.show', $user) : route('admin.users.index') }}" class="btn btn-ghost">{{ __('Cancel') }}</a>
        <button type="submit" class="btn btn-primary">{{ $submit }}</button>
    </div>
</div>

@push('scripts')
<script>
    // Fields that only belong to one role (admin form): the reseller's limits, a subscriber's reseller
    (() => {
        const role = document.getElementById('role');
        if (!role) return;
        const update = () => document.querySelectorAll('[data-role-panel]').forEach((panel) => {
            panel.hidden = panel.dataset.rolePanel !== role.value;
        });
        role.addEventListener('change', update);
        update();
    })();

    document.querySelectorAll('[data-extend]').forEach((button) => button.addEventListener('click', () => {
        const input = document.getElementById('expiration_date');
        const months = Number(button.dataset.extend);
        if (!months) { input.value = ''; return; }
        const base = input.value && new Date(input.value) > new Date() ? new Date(input.value) : new Date();
        base.setMonth(base.getMonth() + months);
        input.value = base.toISOString().slice(0, 10);
    }));
</script>
@endpush
