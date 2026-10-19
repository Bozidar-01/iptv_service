<?php

use App\Models\Bouquet;
use App\Models\Channel;
use App\Models\Source;
use App\Models\User;
use App\Services\ChannelDuplicateService;
use App\Services\StreamCheckService;
use Illuminate\Support\Facades\Http;

const MASTER = "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=800000,AVERAGE-BANDWIDTH=700000,RESOLUTION=640x360\nlow.m3u8\n#EXT-X-STREAM-INF:BANDWIDTH=4500000,RESOLUTION=1920x1080,CODECS=\"avc1\"\nhigh.m3u8\n#EXT-X-STREAM-INF:BANDWIDTH=2000000,RESOLUTION=1280x720\nmid.m3u8\n";

function qChannel(string $url, array $attrs = []): Channel
{
    return Channel::create(array_merge(['name' => $url, 'stream_url' => $url, 'is_active' => true], $attrs));
}

describe('reading a playlist', function () {
    it('finds the best picture of a master playlist', function () {
        expect(StreamCheckService::parseQuality(MASTER))->toBe([1080, 4500]);
    });

    it('works with a bitrate only, and ignores what is not a master playlist', function () {
        expect(StreamCheckService::parseQuality("#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1500000\nx.m3u8\n"))->toBe([0, 1500])
            ->and(StreamCheckService::parseQuality("#EXTM3U\n#EXT-X-VERSION:3\n#EXTINF:6,\nseg1.ts\n"))->toBeNull()
            ->and(StreamCheckService::parseQuality(str_repeat("\x47", 400)))->toBeNull()
            ->and(StreamCheckService::parseQuality(''))->toBeNull();
    });
});

describe('the stream check', function () {
    it('saves the measured quality of working HLS channels and leaves the others alone', function () {
        Http::fake([
            'master.test/*' => fn () => Http::response(MASTER, 200, ['Content-Type' => 'application/vnd.apple.mpegurl']),
            'media.test/*' => fn () => Http::response("#EXTM3U\n#EXTINF:6,\nseg1.ts\n", 200),
            'ts.test/*' => fn () => Http::response(str_repeat("\x47", 376), 200, ['Content-Type' => 'video/mp2t']),
            'gone.test/*' => fn () => Http::response('Not found', 404),
        ]);
        $master = qChannel('http://master.test/a.m3u8');
        $media = qChannel('http://media.test/a.m3u8', ['quality_height' => 720, 'quality_kbps' => 2000]);
        $ts = qChannel('http://ts.test/a.ts');
        $gone = qChannel('http://gone.test/a.m3u8');
        $this->travel(0)->seconds();

        app(StreamCheckService::class)->checkChannels([$master->id, $media->id, $ts->id, $gone->id]);

        expect($master->fresh())->quality_height->toBe(1080)->quality_kbps->toBe(4500)
            // a media playlist or raw stream tells nothing new: what was known stays
            ->and($media->fresh())->quality_height->toBe(720)->and($ts->fresh()->quality_height)->toBeNull()->and($gone->fresh()->quality_height)->toBeNull();
    });
});

describe('Find duplicates', function () {
    it('prefers a measured picture to the tag in the name, then the higher bitrate', function () {
        expect(ChannelDuplicateService::qualityOf('News HD', 2160))->toBe(5)->and(ChannelDuplicateService::qualityOf('News 4K', 576))->toBe(1)
            ->and(ChannelDuplicateService::qualityOf('News', 1080))->toBe(4)->and(ChannelDuplicateService::qualityOf('News', 720))->toBe(3)
            ->and(ChannelDuplicateService::qualityOf('News HD', null))->toBe(3)->and(ChannelDuplicateService::qualityOf('News HD', 0))->toBe(3);
    });

    it('suggests the copy that measures best', function () {
        $bouquet = Bouquet::create(['name' => 'Main']);
        $a = Source::create(['name' => 'A', 'type' => 'url', 'url' => 'http://a.test/a.m3u']);
        // "News HD" is really 480p, "News" is really 1080p
        $tagged = qChannel('http://x.test/1', ['name' => 'News HD', 'stream_status' => 'online', 'quality_height' => 480, 'quality_kbps' => 900, 'source_id' => $a->id]);
        $plain = qChannel('http://x.test/2', ['name' => 'News', 'stream_status' => 'online', 'quality_height' => 1080, 'quality_kbps' => 5000]);
        $bouquet->channels()->attach([$tagged->id, $plain->id]);

        $group = app(ChannelDuplicateService::class)->groups()[0];

        expect($group['suggested'])->toBe($plain->id)->and($group['channels'][0]['measured'])->toBe(['height' => 1080, 'kbps' => 5000]);

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.channels.duplicates'))->assertSee('1080p')->assertSee('5.0 Mbps')->assertSee('480p');
    });
});
