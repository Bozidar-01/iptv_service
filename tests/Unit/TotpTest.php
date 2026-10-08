<?php

use App\Support\Totp;

// RFC 6238 test secret "12345678901234567890" (SHA-1); the RFC lists 8 digits, these are the last 6
const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

it('makes the codes from the RFC 6238 examples', function (int $time, string $code) {
    expect(Totp::code(RFC_SECRET, Totp::step($time)))->toBe($code);
})->with([
    [59, '287082'],
    [1111111109, '081804'],
    [1234567890, '005924'],
    [2000000000, '279037'],
]);

it('encodes and decodes base32', function () {
    expect(Totp::base32Encode('12345678901234567890'))->toBe(RFC_SECRET)
        ->and(Totp::base32Decode(RFC_SECRET))->toBe('12345678901234567890')
        ->and(strlen(Totp::generateSecret()))->toBe(32);
});

it('accepts the current code and one step of clock drift, nothing else', function () {
    $time = 1234567890;
    $step = Totp::step($time);

    expect(Totp::verify(RFC_SECRET, '005924', null, $time))->toBe($step)
        ->and(Totp::verify(RFC_SECRET, '005 924', null, $time))->toBe($step)
        ->and(Totp::verify(RFC_SECRET, Totp::code(RFC_SECRET, $step - 1), null, $time))->toBe($step - 1)
        ->and(Totp::verify(RFC_SECRET, Totp::code(RFC_SECRET, $step + 1), null, $time))->toBe($step + 1)
        ->and(Totp::verify(RFC_SECRET, Totp::code(RFC_SECRET, $step + 2), null, $time))->toBeNull()
        ->and(Totp::verify(RFC_SECRET, '12345', null, $time))->toBeNull()
        ->and(Totp::verify(RFC_SECRET, 'abcdef', null, $time))->toBeNull();
});

it('refuses a step that was already used', function () {
    $time = 1234567890;

    expect(Totp::verify(RFC_SECRET, '005924', Totp::step($time), $time))->toBeNull();
});

it('builds the address for authenticator apps', function () {
    $uri = Totp::uri('ABC234', 'admin', 'NeboTV');

    expect($uri)->toStartWith('otpauth://totp/NeboTV%3Aadmin?')
        ->and($uri)->toContain('secret=ABC234')->toContain('issuer=NeboTV')->toContain('period=30');
});
