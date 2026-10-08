<?php

use App\Support\SentryScrubber;
use Sentry\Event;

it('hides tokens, passwords and provider logins in addresses', function () {
    expect(SentryScrubber::url('https://tv.example.com/play/12/SECRETTOKEN.m3u8'))->toBe('https://tv.example.com/play/[hidden]')
        ->and(SentryScrubber::url('https://tv.example.com/playlist/SECRETTOKEN.m3u'))->toBe('https://tv.example.com/playlist/[hidden]')
        ->and(SentryScrubber::url('http://tv.example.com:8000/live/bob/hunter2/5.ts?x=1'))->toBe('http://tv.example.com:8000/live/[hidden]')
        ->and(SentryScrubber::url('https://tv.example.com/admin/users'))->toBe('https://tv.example.com/admin/users')
        ->and(SentryScrubber::url('not a url'))->toBe('[hidden]');
});

it('cuts addresses in messages down to the host', function () {
    $text = SentryScrubber::text('cURL error 28 for https://user:pass@provider.test:8080/get.php?username=u&password=p: timed out');

    expect($text)->toBe('cURL error 28 for https://provider.test/[hidden]: timed out')
        ->and(SentryScrubber::text(null))->toBeNull()
        ->and(SentryScrubber::text('no address here'))->toBe('no address here');
});

it('cleans a whole event', function () {
    $event = Event::createEvent();
    $event->setRequest([
        'url' => 'https://tv.example.com/m3u?username=bob&password=hunter2',
        'query_string' => 'username=bob&password=hunter2',
        'cookies' => ['laravel_session' => 'abc'],
        'headers' => ['cookie' => 'abc', 'accept' => '*/*'],
    ]);
    $event->setMessage('Failed https://provider.test/x?token=1');

    $clean = SentryScrubber::beforeSend($event);

    expect($clean->getRequest())->toBe(['url' => 'https://tv.example.com/m3u', 'headers' => ['accept' => '*/*']])
        ->and($clean->getMessage())->toBe('Failed https://provider.test/[hidden]');
});
