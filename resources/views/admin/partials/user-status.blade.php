@if($user->status !== 'active')
    <span class="badge badge-danger badge-dot">{{ __('Disabled') }}</span>
@elseif($user->isExpired())
    <span class="badge badge-warning badge-dot">{{ __('Expired') }}</span>
@else
    <span class="badge badge-success badge-dot">{{ __('Active') }}</span>
@endif
@if($user->is_trial)
    <span class="badge badge-info">{{ __('Trial') }}</span>
@endif
