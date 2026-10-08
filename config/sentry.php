<?php

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
    'before_send' => [\App\Support\SentryScrubber::class, 'beforeSend'],

];
