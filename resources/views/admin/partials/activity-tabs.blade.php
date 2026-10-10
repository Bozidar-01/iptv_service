<div class="page-actions" style="margin-bottom: 16px">
    <a href="{{ route('admin.logs.index') }}" class="btn {{ request()->routeIs('admin.logs.index') ? 'btn-primary' : 'btn-secondary' }} btn-sm">{{ __('Plays') }}</a>
    <a href="{{ route('admin.logs.refused') }}" class="btn {{ request()->routeIs('admin.logs.refused') ? 'btn-primary' : 'btn-secondary' }} btn-sm">{{ __('Refused') }}</a>
</div>
