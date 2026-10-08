<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Services\StreamProxyService;
use App\Services\StreamService;
use App\Services\TokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlayController extends Controller
{
    protected $streamService;

    protected $tokenService;

    protected $proxyService;

    public function __construct(StreamService $streamService, TokenService $tokenService, StreamProxyService $proxyService)
    {
        $this->streamService = $streamService;
        $this->tokenService = $tokenService;
        $this->proxyService = $proxyService;
    }

    public function play(Request $request, $channel_id, $token)
    {
        // 1. Validate Token (play URLs may carry a stream extension, e.g. "abc123.m3u8")
        $accessToken = $this->tokenService->validateToken(Str::before($token, '.'));
        if (!$accessToken) {
            return response('Invalid or expired token', 401);
        }

        $user = $accessToken->user;
        $channel = Channel::find($channel_id);

        if (!$channel) {
            return response('Channel not found', 404);
        }

        // 2. Validate Access (Subscription, Status, Max Connections)
        if (!$this->streamService->isAllowed($user, $channel)) {
            return response('Access denied or connection limit reached', 403);
        }

        // 3. Get Stream URL (Log attempt inside service)
        $streamUrl = $this->streamService->getStreamUrl($user, $channel);

        // 4. Redirect to the stream URL, or pass the stream on for sources that hide the provider address
        return $this->proxyService->respond($request, $user, $channel, $accessToken->token, $streamUrl);
    }

    /**
     * GET /stream/{channel}/{token}?u=: a signed part of a proxied stream (HLS segment, key, variant).
     */
    public function stream(Request $request, $channel_id, $token)
    {
        $accessToken = $this->tokenService->validateToken($token);
        if (!$accessToken) {
            return response('Invalid or expired token', 401);
        }

        $channel = Channel::find($channel_id);
        if (!$channel) {
            return response('Channel not found', 404);
        }

        $target = $this->proxyService->decodePart($channel, $request->query('u'));
        if ($target === null) {
            return response('Forbidden', 403);
        }

        $user = $accessToken->user;
        if (!$this->streamService->isAllowed($user, $channel)) {
            return response('Access denied or connection limit reached', 403);
        }

        return $this->proxyService->respondPart($request, $user, $channel, $accessToken->token, $target);
    }
}
