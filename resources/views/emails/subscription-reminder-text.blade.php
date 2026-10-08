{{-- Plain-text part: {!! !!} because this is not HTML --}}
{!! config('app.name') !!}

{!! __('Hello, :name', ['name' => $user->username]) !!}

@if($expired)
{!! __('Your subscription ended on :date.', ['date' => $date]) !!}

{!! __('The channels will not load until it is renewed.') !!}
@else
{!! __('Your subscription ends on :date.', ['date' => $date]) !!}

{!! __('To keep watching without a break, renew before then.') !!}
@endif

{!! __('Open my page and ask to renew') !!}: {!! $pageUrl !!}
@if($contact)

{!! $contact !!}
@endif

{!! __('You get this email because your address is on your :app account.', ['app' => config('app.name')]) !!}
