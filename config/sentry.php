<?php

use App\Support\SentryScrubber;

/**
 * Sentry error tracking. Only what differs from the package defaults is set here
 * (see vendor/sentry/sentry-laravel/config/sentry.php). No DSN = nothing is sent.
 */
return [

    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),

    // Never send IPs, cookies or user details
    'send_default_pii' => false,

    // Tokens, passwords and provider logins are removed from addresses and messages
    // (a class callable instead of a closure, so `config:cache` works)
    'before_send' => [SentryScrubber::class, 'beforeSend'],
    'before_send_transaction' => [SentryScrubber::class, 'beforeSend'],
    'before_breadcrumb' => [SentryScrubber::class, 'beforeBreadcrumb'],

    // Calls to providers carry their login in the address: don't record them at all
    'breadcrumbs' => ['http_client_requests' => false],
    'tracing' => ['http_client_requests' => false],

];
