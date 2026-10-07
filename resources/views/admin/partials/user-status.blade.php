@if($user->status !== 'active')
    <span class="badge badge-danger badge-dot">Disabled</span>
@elseif($user->isExpired())
    <span class="badge badge-warning badge-dot">Expired</span>
@else
    <span class="badge badge-success badge-dot">Active</span>
@endif
