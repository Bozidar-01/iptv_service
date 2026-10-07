<?php

use App\Services\M3uParser;

function parse(string $playlist): array
{
    return iterator_to_array((new M3uParser)->parseLines(explode("\n", $playlist)), false);
}

it('parses extended M3U entries with attributes', function () {
    $entries = parse(<<<'M3U'
        #EXTM3U url-tvg="http://epg.test/guide.xml"
        #EXTINF:-1 tvg-id="bbc1.uk" tvg-name="BBC One" tvg-logo="http://logo.test/bbc1.png" group-title="UK | General",BBC One HD
        http://provider.test/live/u/p/101.ts
        M3U);

    expect($entries)->toBe([[
        'name' => 'BBC One HD',
        'logo' => 'http://logo.test/bbc1.png',
        'group' => 'UK | General',
        'tvg_id' => 'bbc1.uk',
        'url' => 'http://provider.test/live/u/p/101.ts',
    ]]);
});

it('handles commas inside attributes and in the title', function () {
    $entries = parse(<<<'M3U'
        #EXTM3U
        #EXTINF:-1 group-title="News, World",CNN, International
        http://x.test/1
        M3U);

    expect($entries[0]['group'])->toBe('News, World')
        ->and($entries[0]['name'])->toBe('International');
});

it('skips option lines, supports #EXTGRP, BOM and bare URLs', function () {
    $entries = parse("\xEF\xBB\xBF#EXTM3U\n#EXTINF:-1,Channel A\n#EXTGRP:Kids\n#EXTVLCOPT:http-user-agent=Foo\nhttp://x.test/a.m3u8\n\nhttp://x.test/path/bare-stream.m3u8\n");

    expect($entries)->toHaveCount(2)
        ->and($entries[0]['name'])->toBe('Channel A')
        ->and($entries[0]['group'])->toBe('Kids')
        ->and($entries[0]['url'])->toBe('http://x.test/a.m3u8')
        ->and($entries[1]['name'])->toBe('bare-stream')
        ->and($entries[1]['group'])->toBeNull();
});

it('falls back to tvg-name when the title is empty', function () {
    expect(parse("#EXTINF:-1 tvg-name=\"Fallback\",\nhttp://x.test/1")[0]['name'])->toBe('Fallback');
});

it('detects VOD entries', function (string $url, bool $vod) {
    expect((new M3uParser)->isVod($url))->toBe($vod);
})->with([
    ['http://p.test/movie/u/p/123.mkv', true],
    ['http://p.test/series/u/p/456.mp4', true],
    ['http://p.test/films/file.mp4', true],
    ['http://p.test/live/u/p/101.ts', false],
    ['http://p.test/u/p/101', false],
    ['http://p.test/hls/stream.m3u8', false],
]);
