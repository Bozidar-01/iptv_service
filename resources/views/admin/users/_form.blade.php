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
            @isset($user)
                @if($user->password_plain)
                    <div class="copy-field" style="margin-top: 8px">
                        <input class="input mono" id="current-password" type="password" value="{{ $user->password_plain }}" readonly aria-label="{{ __('Current password') }}">
                        <button type="button" class="btn btn-secondary" data-reveal="#current-password"><x-icon name="eye" /> <span data-reveal-label>{{ __('Show current password') }}</span></button>
                    </div>
                @else
                    <span class="help">{{ __('The current password is not stored for this user. Set a new one to see it here.') }}</span>
                @endif
            @endisset
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
            @unless(isset($user))
                <div class="field" data-role-panel="reseller">
                    <label class="label" for="initial_credits">{{ __('Starting credits') }} <span class="optional">{{ __('(optional)') }}</span></label>
                    <input class="input" id="initial_credits" name="initial_credits" type="number" min="0" max="1000000" value="{{ old('initial_credits') }}">
                    <span class="help">{{ __('1 credit = 1 month for one user. Empty = not limited by credits; 0 = limited, nothing to give yet.') }}</span>
                </div>
            @endunless
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
            @if(Auth::user()->usesCredits())
                <span class="help" id="credit-cost" data-balance="{{ Auth::user()->credits }}" data-current="{{ isset($user) ? $user->expiration_date?->toDateString() : '' }}"></span>
            @endif
            <div style="display: flex; gap: 6px; flex-wrap: wrap">
                @foreach([1 => __('+1 month'), 3 => __('+3 months'), 12 => __('+1 year')] as $months => $label)
                    <button type="button" class="btn btn-ghost btn-sm" data-extend="{{ $months }}">{{ $label }}</button>
                @endforeach
                @unless(Auth::user()->usesCredits())
                    <button type="button" class="btn btn-ghost btn-sm" data-extend="0">{{ __('Never') }}</button>
                @endunless
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

    // Live preview of what the end date costs a reseller who is limited by credits. The server decides (App\Support\Credits):
    // keep this the same rule: whole calendar months from the day the current subscription ends (or today) to the new end date.
    (() => {
        const box = document.getElementById('credit-cost');
        const input = document.getElementById('expiration_date');
        if (!box || !input) return;
        const balance = Number(box.dataset.balance);
        const one = @json(trans_choice('This costs :cost credit. You have :balance.|This costs :cost credits. You have :balance.', 1));
        const many = @json(trans_choice('This costs :cost credit. You have :balance.|This costs :cost credits. You have :balance.', 2));
        const day = (value) => { const d = new Date(value + 'T00:00:00'); return d; };
        const addMonths = (date, months) => {
            const d = new Date(date.getFullYear(), date.getMonth() + months, 1);
            d.setDate(Math.min(date.getDate(), new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate()));
            return d;
        };
        const update = () => {
            if (!input.value) { box.textContent = ''; return; }
            const today = new Date(); today.setHours(0, 0, 0, 0);
            let from = today;
            if (box.dataset.current && day(box.dataset.current) > today) from = day(box.dataset.current);
            const to = day(input.value);
            // Same rule as App\Support\Credits: one extra day is free (time zones), and any longer subscription costs at least 1
            let months = 0;
            if (to > from) {
                const limit = new Date(to); limit.setDate(limit.getDate() - 1);
                while (addMonths(from, months) < limit && months < 1200) months++;
                months = Math.max(1, months);
            }
            box.textContent = (months === 1 ? one : many).replace(':cost', months).replace(':balance', balance);
            box.style.color = months > balance ? 'var(--danger)' : '';
        };
        input.addEventListener('input', update);
        document.querySelectorAll('[data-extend]').forEach((button) => button.addEventListener('click', () => setTimeout(update, 0)));
        update();
    })();

    // The end-date shortcuts. Dates are handled in the browser's own day (not UTC, which can be a day off), and
    // months are added like the server does: the day is kept, or the last day of a shorter month.
    document.querySelectorAll('[data-extend]').forEach((button) => button.addEventListener('click', () => {
        const input = document.getElementById('expiration_date');
        const months = Number(button.dataset.extend);
        if (!months) { input.value = ''; return; }
        const today = new Date(); today.setHours(0, 0, 0, 0);
        const current = input.value ? new Date(input.value + 'T00:00:00') : null;
        const base = current && current > today ? current : today;
        const target = new Date(base.getFullYear(), base.getMonth() + months, 1);
        target.setDate(Math.min(base.getDate(), new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate()));
        input.value = [target.getFullYear(), String(target.getMonth() + 1).padStart(2, '0'), String(target.getDate()).padStart(2, '0')].join('-');
        input.dispatchEvent(new Event('input'));
    }));
</script>
@endpush
