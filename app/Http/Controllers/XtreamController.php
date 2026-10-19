<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Services\EpgService;
use App\Services\FavoriteService;
use App\Services\ListCacheService;
use App\Services\StreamProxyService;
use App\Services\StreamService;
use App\Services\TokenService;
use App\Services\XtreamService;
use App\Support\GzipOutput;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Xtream Codes API endpoints (see XtreamService). /get.php is served by M3uController.
 */
class XtreamController extends Controller
{
    protected $xtreamService;

    protected $streamService;

    public function __construct(XtreamService $xtreamService, StreamService $streamService)
    {
        $this->xtreamService = $xtreamService;
        $this->streamService = $streamService;
    }

    /**
     * GET|POST /player_api.php?username=&password=[&action=...]
     */
    public function api(Request $request)
    {
        $user = $this->xtreamService->authenticate($request->input('username'), $request->input('password'));

        if (!$user) {
            return response()->json(['user_info' => ['auth' => 0]]);
        }

        $action = $request->input('action');

        if (!$action) {
            return response()->json($this->xtreamService->loginInfo($user, $request->input('password'), $request));
        }

        // Disabled or expired accounts can log in (so the app can show why) but get no channels
        if ($this->xtreamService->status($user) !== 'Active') {
            return response()->json([]);
        }

        return match ($action) {
            'get_live_categories' => response()->json($this->xtreamService->liveCategories($user)),
            'get_live_streams' => $this->streamLiveStreams($request, $user, $request->input('category_id')),
            'get_short_epg' => $this->epgListings($user, $request, false),
            'get_simple_data_table' => $this->epgListings($user, $request, true),
            // Live TV only for now: VOD, series and anything else are empty
            default => response()->json([]),
        };
    }

    /**
     * GET /live/{username}/{password}/{id}.{ts|m3u8}: same checks, logging and limits as /play.
     */
    public function live(Request $request, StreamProxyService $proxy, TokenService $tokens, string $username, string $password, string $stream)
    {
        $user = $this->xtreamService->authenticate($username, $password);
        if (!$user) {
            return response('Invalid credentials', 401);
        }

        $channel = Channel::find((int) Str::before($stream, '.'));
        if (!$channel) {
            return response('Channel not found', 404);
        }

        if (!$this->streamService->authorize($user, $channel)) {
            return response('Access denied or connection limit reached', 403);
        }

        // Redirect to the stream, or pass it on for sources that hide the provider address
        return $proxy->respond($request, $user, $channel, $tokens->playbackToken($user)->token, $this->streamService->getStreamUrl($user, $channel));
    }

    /**
     * GET /xmltv.php?username=&password=: the TV guide for the user's channels.
     */
    public function xmltv(Request $request, EpgService $epg)
    {
        $user = $this->xtreamService->authenticate($request->input('username'), $request->input('password'));

        if (!$user || $this->xtreamService->status($user) !== 'Active') {
            return response("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<tv></tv>\n")->header('Content-Type', 'application/xml');
        }

        $gzip = new GzipOutput($request);

        return response()->stream(function () use ($epg, $user, $gzip) {
            $epg->writeXmltv($user, fn ($chunk) => $gzip->write($chunk));
            $gzip->finish();
        }, 200, ['Content-Type' => 'application/xml; charset=utf-8', ...$gzip->headers()]);
    }

    /**
     * get_short_epg (next few programmes, &limit=) and get_simple_data_table (today onwards) for &stream_id=.
     */
    private function epgListings($user, Request $request, bool $fullDay)
    {
        $channel = Channel::availableTo($user)->find((int) $request->input('stream_id'));

        if (!$channel) {
            return response()->json(['epg_listings' => []]);
        }

        $limit = $fullDay ? null : max(1, min(50, (int) ($request->input('limit') ?: 4)));

        return response()->json(['epg_listings' => app(EpgService::class)->xtreamListings($channel, $limit, $fullDay)]);
    }

    /**
     * The channel list can have tens of thousands of entries: write the JSON as it is read, compressed when the app accepts it.
     * The full list comes from a ready file shared by viewers who see the same channels (ListCacheService).
     */
    private function streamLiveStreams(Request $request, $user, $categoryId)
    {
        $favouritesOnly = (string) $categoryId === FavoriteService::XTREAM_CATEGORY;
        $everything = !$favouritesOnly && !is_numeric($categoryId);
        // Favourites are listed first as their own category, and with no category chosen also among the rest
        $favourites = $favouritesOnly || $everything ? $this->xtreamService->favouriteStreams($user) : collect();
        $gzip = new GzipOutput($request);

        $writeRest = function (callable $write, bool $first) use ($user, $categoryId, $favouritesOnly) {
            if ($favouritesOnly) {
                return;
            }
            $num = 0;
            foreach ($this->xtreamService->liveStreamsQuery($user, $categoryId)->lazy(1000) as $channel) {
                $write(($first && $num === 0 ? '' : ',').json_encode($this->xtreamService->liveStream($channel, ++$num), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        };

        return response()->stream(function () use ($user, $favourites, $everything, $writeRest, $gzip) {
            $gzip->write('[');
            $num = 0;
            foreach ($favourites as $channel) {
                $gzip->write(($num ? ',' : '').json_encode($this->xtreamService->liveStream($channel, ++$num, FavoriteService::XTREAM_CATEGORY), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }

            if ($everything) {
                // Written without the leading comma, so it fits after the favourites or alone
                $path = app(ListCacheService::class)->path($user, 'xtream', fn (callable $write) => $writeRest($write, true));
                $handle = fopen($path, 'r');
                $firstChunk = true;
                while (!feof($handle) && ($chunk = fread($handle, 65536)) !== false && $chunk !== '') {
                    $gzip->write(($firstChunk && $num > 0 ? ',' : '').$chunk);
                    $firstChunk = false;
                }
                fclose($handle);
            } else {
                $writeRest(fn (string $part) => $gzip->write($part), $num === 0);
            }

            $gzip->write(']');
            $gzip->finish();
        }, 200, ['Content-Type' => 'application/json', ...$gzip->headers()]);
    }
}
