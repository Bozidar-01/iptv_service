<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Services\StreamService;
use App\Services\XtreamService;
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
            'get_live_streams' => $this->streamLiveStreams($user, $request->input('category_id')),
            'get_short_epg', 'get_simple_data_table' => response()->json(['epg_listings' => []]),
            // Live TV only for now: VOD, series and anything else are empty
            default => response()->json([]),
        };
    }

    /**
     * GET /live/{username}/{password}/{id}.{ts|m3u8}: same checks, logging and limits as /play.
     */
    public function live(string $username, string $password, string $stream)
    {
        $user = $this->xtreamService->authenticate($username, $password);
        if (!$user) {
            return response('Invalid credentials', 401);
        }

        $channel = Channel::find((int) Str::before($stream, '.'));
        if (!$channel) {
            return response('Channel not found', 404);
        }

        if (!$this->streamService->isAllowed($user, $channel)) {
            return response('Access denied or connection limit reached', 403);
        }

        return redirect()->away($this->streamService->getStreamUrl($user, $channel));
    }

    /**
     * GET /xmltv.php: apps ask for it after login. No EPG yet, so an empty guide.
     */
    public function xmltv()
    {
        return response("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<tv generator-info-name=\"".e(config('app.name'))."\"></tv>\n")
            ->header('Content-Type', 'application/xml');
    }

    /**
     * The channel list can have tens of thousands of entries: write the JSON as it is read.
     */
    private function streamLiveStreams($user, $categoryId)
    {
        $query = $this->xtreamService->liveStreamsQuery($user, $categoryId);

        return response()->stream(function () use ($query) {
            echo '[';
            $num = 0;
            foreach ($query->lazy(1000) as $channel) {
                echo ($num ? ',' : '').json_encode($this->xtreamService->liveStream($channel, ++$num), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            echo ']';
        }, 200, ['Content-Type' => 'application/json']);
    }
}
