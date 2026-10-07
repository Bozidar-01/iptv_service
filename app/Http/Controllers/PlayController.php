<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Services\StreamService;
use App\Services\TokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlayController extends Controller
{
    protected $streamService;
    protected $tokenService;

    public function __construct(StreamService $streamService, TokenService $tokenService)
    {
        $this->streamService = $streamService;
        $this->tokenService = $tokenService;
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

        // 4. Redirect to stream URL
        return redirect()->away($streamUrl);
    }
}
