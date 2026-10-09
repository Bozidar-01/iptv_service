{{-- Renew a subscriber: pick a length and press Renew. A reseller with credits is told what it costs. --}}
@php($pays = Auth::user()->isReseller() && Auth::user()->usesCredits())
<form method="POST" action="{{ route('admin.users.renew', $user) }}" class="inline-form" data-confirm="{{ __('Renew :name for the chosen time?', ['name' => $user->username]) }}" style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center">
    @csrf
    <select class="select" name="months" aria-label="{{ __('How long') }}" style="width: auto">
        @foreach(\App\Http\Controllers\Admin\UserController::RENEW_MONTHS as $months)
            <option value="{{ $months }}">{{ trans_choice(':count month|:count months', $months, ['count' => $months]) }}@if($pays) · {{ trans_choice(':count credit|:count credits', $months, ['count' => $months]) }}@endif</option>
        @endforeach
    </select>
    <button class="btn btn-primary btn-sm"><x-icon name="refresh" /> {{ __('Renew') }}</button>
</form>
