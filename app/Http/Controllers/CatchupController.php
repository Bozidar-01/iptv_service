<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\User;
use App\Services\CatchupService;
use App\Services\StreamProxyService;
use App\Services\StreamService;
use App\Services\TokenService;
use App\Services\XtreamService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Catch-up links (CatchupService): the same access checks, limits and logging as /play, then the provider's archive address.
 */
class CatchupController extends Controller
{
    protected $streamService;

    protected $catchupService;

    protected $proxyService;

    public function __construct(StreamService $streamService, CatchupService $catchupService, StreamProxyService $proxyService)
    {
        $this->streamService = $streamService;
        $this->catchupService = $catchupService;
        $this->proxyService = $proxyService;
    }

    /**
     * GET /catchup/{channel_id}/{token}?utc={start}&duration={seconds}: from the M3U playlist's catchup-source.
     */
    public function play(Request $request, TokenService $tokens, $channel_id, $token)
    {
        [$tokenValue, $deviceKey] = TokenService::parsePlayToken($token);
        $accessToken = $tokens->validateToken($tokenValue);
        if (!$accessToken) {
            return response('Invalid or expired token', 401);
        }
        $request->attributes->set('device_key', $deviceKey);

        $start = is_numeric($request->query('utc')) ? Carbon::createFromTimestamp((int) $request->query('utc')) : null;

        return $this->answer($request, $accessToken->user, Channel::find($channel_id), $start, (int) $request->query('duration', 3600), Str::before($token, '.'));
    }

    /**
     * GET /timeshift/{username}/{password}/{minutes}/{Y-m-d:H-i}/{stream}.ts: Xtream apps (start in the server_info time zone).
     */
    public function timeshift(Request $request, XtreamService $xtream, TokenService $tokens, string $username, string $password, string $duration, string $start, string $stream)
    {
        $user = $xtream->authenticate($username, $password);
        if (!$user) {
            return response('Invalid credentials', 401);
        }

        return $this->answer($request, $user, Channel::find((int) Str::before($stream, '.')), $this->xtreamTime($start), (int) $duration * 60, $tokens->playbackToken($user)->token);
    }

    /**
     * GET /streaming/timeshift.php?username=&password=&stream=&start=&duration=: the same, as some apps ask for it.
     */
    public function timeshiftPhp(Request $request, XtreamService $xtream, TokenService $tokens)
    {
        $user = $xtream->authenticate($request->query('username'), $request->query('password'));
        if (!$user) {
            return response('Invalid credentials', 401);
        }

        return $this->answer($request, $user, Channel::find((int) $request->query('stream')), $this->xtreamTime((string) $request->query('start')), (int) $request->query('duration') * 60, $tokens->playbackToken($user)->token);
    }

    private function answer(Request $request, User $user, ?Channel $channel, ?Carbon $start, int $seconds, string $token)
    {
        if (!$channel) {
            return response('Channel not found', 404);
        }
        if ($start === null) {
            return response('Missing start time', 400);
        }
        if (!$this->streamService->authorize($user, $channel)) {
            return response('Access denied or connection limit reached', 403);
        }

        // Logs the play and keeps the device's slot, like /play (also picks the copy a retrying device was moved to)
        $this->streamService->getStreamUrl($user, $channel);
        $url = $this->catchupService->providerUrl($channel->playbackChannel(), $start, $seconds);
        if ($url === null) {
            return response('No catch-up for this channel at that time', 404);
        }

        return $this->proxyService->respond($request, $user, $channel, $token, $url);
    }

    private function xtreamTime(string $value): ?Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m-d:H-i', $value, config('app.timezone')) ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
