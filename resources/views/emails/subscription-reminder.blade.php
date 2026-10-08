{{-- Reminder to a subscriber. Email-safe: tables and inline styles only (Gmail, Outlook, Apple Mail). --}}
@php
    $font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
    $accent = $expired ? '#d92d20' : '#b54708';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ config('app.name') }}</title>
</head>
<body style="margin:0;padding:0;background:#f6f7f9;-webkit-text-size-adjust:100%;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f6f7f9;">
    <tr>
        <td align="center" style="padding:32px 16px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:520px;">
                <tr>
                    <td style="padding:0 4px 16px;font-family:{{ $font }};font-size:18px;font-weight:700;color:#111827;">{{ config('app.name') }}</td>
                </tr>
                <tr>
                    <td style="background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;padding:28px;font-family:{{ $font }};font-size:15px;line-height:1.55;color:#374151;">
                        <p style="margin:0 0 14px;">{{ __('Hello, :name', ['name' => $user->username]) }}</p>
                        <p style="margin:0 0 18px;font-size:16px;font-weight:600;color:{{ $accent }};">
                            @if($expired)
                                {{ __('Your subscription ended on :date.', ['date' => $date]) }}
                            @else
                                {{ __('Your subscription ends on :date.', ['date' => $date]) }}
                            @endif
                        </p>
                        <p style="margin:0 0 20px;">{{ $expired ? __('The channels will not load until it is renewed.') : __('To keep watching without a break, renew before then.') }}</p>
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
                            <tr>
                                <td style="background:#4f46e5;border-radius:8px;">
                                    <a href="{{ $pageUrl }}" style="display:inline-block;padding:11px 20px;font-family:{{ $font }};font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">{{ __('Open my page and ask to renew') }}</a>
                                </td>
                            </tr>
                        </table>
                        @if($contact)
                            <p style="margin:0 0 6px;">{{ $contact }}</p>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 4px 0;font-family:{{ $font }};font-size:12px;line-height:1.5;color:#6b7280;">
                        {{ __('You get this email because your address is on your :app account.', ['app' => config('app.name')]) }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
