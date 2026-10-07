{{-- Alert digest / test email. Email-safe: tables and inline styles only (Gmail, Outlook, Apple Mail). --}}
@php
    $isTest = $alerts->isEmpty();
    $problems = $alerts->filter->isProblem()->count();
    $recovered = $alerts->count() - $problems;
    $styles = [
        'system' => ['#d92d20', '#fef3f2', __('Problem')],
        'subscriptions' => ['#b54708', '#fffaeb', __('Subscription')],
        'sync_failed' => ['#b54708', '#fef6ee', __('Import failed')],
        'down' => ['#d92d20', '#fef3f2', __('Not working')],
        'up' => ['#079455', '#ecfdf3', __('Working again')],
    ];
    $headings = [
        'system' => __('Background tasks'),
        'subscriptions' => __('Subscriptions'),
        'sync_failed' => trans_choice(':count source failed to import|:count sources failed to import', count($groups['sync_failed'] ?? []), ['count' => count($groups['sync_failed'] ?? [])]),
        'down' => trans_choice(':count channel stopped working|:count channels stopped working', count($groups['down'] ?? []), ['count' => count($groups['down'] ?? [])]),
        'up' => trans_choice(':count channel is working again|:count channels are working again', count($groups['up'] ?? []), ['count' => count($groups['up'] ?? [])]),
    ];
    $font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
    // Inline (CID) image so it shows without loading remote images; a plain URL when previewed in a browser
    $icon = isset($message) ? $message->embed(public_path('apple-touch-icon.png')) : asset('apple-touch-icon.png');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ config('app.name') }}</title>
</head>
<body style="margin:0;padding:0;background:#f6f7f9;-webkit-text-size-adjust:100%;">
{{-- Preview text shown next to the subject in the inbox --}}
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">
    {{ $isTest ? __('Email alerts are set up correctly.') : collect($headings)->only(array_keys($groups))->implode(' · ') }}
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f6f7f9;">
    <tr>
        <td align="center" style="padding:32px 16px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">

                {{-- Brand --}}
                <tr>
                    <td style="padding:0 4px 20px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="padding-right:10px;vertical-align:middle;">
                                    <img src="{{ $icon }}" width="36" height="36" alt="" style="display:block;border:0;border-radius:9px;">
                                </td>
                                <td style="vertical-align:middle;font-family:{{ $font }};font-size:20px;font-weight:800;letter-spacing:-0.02em;color:#101828;">
                                    Nebo<span style="color:#6366f1;">TV</span>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Card --}}
                <tr>
                    <td style="background:#ffffff;border:1px solid #e4e7ec;border-radius:14px;overflow:hidden;">
                        {{-- Accent bar --}}
                        <div style="height:4px;line-height:4px;font-size:0;background:{{ $isTest ? '#6366f1' : ($problems > 0 ? '#d92d20' : '#079455') }};background-image:linear-gradient(90deg,{{ $isTest ? '#6366f1,#8b5cf6' : ($problems > 0 ? '#d92d20,#f97066' : '#079455,#47cd89') }});">&nbsp;</div>

                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="padding:28px 28px 8px;font-family:{{ $font }};">
                                    @if($isTest)
                                        <h1 style="margin:0 0 8px;font-size:22px;line-height:1.3;font-weight:700;color:#101828;">{{ __('Email alerts are working') }}</h1>
                                        <p style="margin:0;font-size:15px;line-height:1.6;color:#5d6679;">{{ __('This is a test from :app. When a channel or source you watch stops working, you will get an email like this one.', ['app' => config('app.name')]) }}</p>
                                    @else
                                        <h1 style="margin:0 0 8px;font-size:22px;line-height:1.3;font-weight:700;color:#101828;">
                                            {{ $problems > 0
                                                ? trans_choice(':count problem needs your attention|:count problems need your attention', $problems, ['count' => $problems])
                                                : __('Your channels are working again') }}
                                        </h1>
                                        <p style="margin:0;font-size:15px;line-height:1.6;color:#5d6679;">
                                            {{ __('Changes found by the stream check on :app.', ['app' => config('app.name')]) }}
                                            @if($problems > 0 && $recovered > 0)
                                                {{ trans_choice(':count channel recovered.|:count channels recovered.', $recovered, ['count' => $recovered]) }}
                                            @endif
                                        </p>
                                    @endif
                                </td>
                            </tr>

                            @foreach($groups as $type => $items)
                                @php [$color, $soft, $label] = $styles[$type]; @endphp
                                <tr>
                                    <td style="padding:20px 28px 0;font-family:{{ $font }};">
                                        <p style="margin:0 0 10px;font-size:12px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:{{ $color }};">{{ $headings[$type] }}</p>
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e4e7ec;border-radius:10px;border-collapse:separate;">
                                            @foreach($items as $alert)
                                                <tr>
                                                    <td style="padding:12px 14px;{{ $loop->last ? '' : 'border-bottom:1px solid #eef0f3;' }}">
                                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                                            <tr>
                                                                <td style="font-family:{{ $font }};vertical-align:middle;">
                                                                    <div style="font-size:15px;font-weight:600;color:#101828;">{{ $alert->title() }}</div>
                                                                    <div style="padding-top:2px;font-size:13px;color:#8a92a6;">
                                                                        @if($alert->channel_id && $alert->source){{ $alert->source->name }} · @endif{{ $alert->created_at->translatedFormat('j M, H:i') }}
                                                                    </div>
                                                                    @if($alert->reason())
                                                                        <div style="padding-top:2px;font-size:13px;color:#d92d20;">{{ $alert->reason() }}</div>
                                                                    @endif
                                                                </td>
                                                                <td align="right" style="vertical-align:middle;white-space:nowrap;padding-left:12px;">
                                                                    <span style="display:inline-block;padding:4px 10px;border-radius:999px;background:{{ $soft }};color:{{ $color }};font-family:{{ $font }};font-size:12px;font-weight:600;">{{ $label }}</span>
                                                                </td>
                                                            </tr>
                                                        </table>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </table>
                                    </td>
                                </tr>
                            @endforeach

                            {{-- Button --}}
                            <tr>
                                <td style="padding:28px 28px 28px;">
                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                        <tr>
                                            <td style="border-radius:8px;background:#4f46e5;">
                                                <a href="{{ $isTest ? route('admin.dashboard') : route('admin.alerts.index') }}" style="display:inline-block;padding:12px 22px;font-family:{{ $font }};font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;">
                                                    {{ $isTest ? __('Open :app', ['app' => config('app.name')]) : __('Open alerts') }} &rarr;
                                                </a>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td style="padding:20px 8px 0;font-family:{{ $font }};font-size:12px;line-height:1.6;color:#8a92a6;text-align:center;">
                        {{ __('You get this email because :app sends its alerts to this address. You can turn alerts off for any channel or source in the admin panel.', ['app' => config('app.name')]) }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
