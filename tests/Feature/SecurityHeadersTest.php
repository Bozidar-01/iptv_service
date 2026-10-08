<?php

it('sends security headers on panel pages', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'same-origin')
        ->assertHeaderMissing('Strict-Transport-Security');
});

it('sends HSTS over HTTPS only', function () {
    $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security');
});

it('leaves the TV app endpoints without panel headers', function () {
    $this->get(route('health'))->assertHeaderMissing('X-Frame-Options');
});
