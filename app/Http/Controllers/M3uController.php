<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\EpgService;
use App\Services\TokenService;
use App\Services\XtreamService;
use Illuminate\Http\Request;

class M3uController extends Controller
{
    protected $tokenService;

    public function __construct(TokenService $tokenService)
    {
        $this->tokenService = $tokenService;
    }

    /**
     * GET /m3u?username=&password=, and the Xtream Codes /get.php?username=&password=&type=m3u_plus
     */
    public function generate(Request $request, XtreamService $xtream)
    {
        $user = $xtream->authenticate($request->input('username'), $request->input('password'));

        if (!$user) {
            return response('Invalid credentials', 401);
        }

        return $this->playlistFor($user, route('xtream.xmltv', ['username' => $user->username, 'password' => $request->input('password')]));
    }

    /**
     * GET /playlist/{token}.m3u: the shareable link shown in the admin panel.
     */
    public function playlist(string $token)
    {
        $user = User::where('playlist_token', $token)->first();

        if (!$user) {
            return response('Playlist not found', 404);
        }

        return $this->playlistFor($user, route('epg', ['token' => $token]));
    }

    /**
     * GET /epg/{token}.xml: TV guide for the playlist link (linked from its header).
     */
    public function epg(string $token, EpgService $epg)
    {
        $user = User::where('playlist_token', $token)->first();

        if (!$user || !$user->isActive()) {
            return response('Guide not found', 404);
        }

        return response()->stream(fn () => $epg->writeXmltv($user, function ($chunk) {
            echo $chunk;
        }), 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    private function playlistFor(User $user, ?string $guideUrl = null)
    {
        if ($user->status !== 'active') {
            return response('Account disabled', 403);
        }

        if ($user->isExpired()) {
            return response('Account expired', 403);
        }

        $token = $this->tokenService->playbackToken($user);

        // Streamed in chunks: a big playlist is never held in memory
        return response()->stream(
            function () use ($user, $token, $guideUrl) {
                try {
                    $this->tokenService->writeM3u($user, $token->token, $guideUrl, function (string $chunk) {
                        echo $chunk;
                        flush();
                    });
                } catch (\Throwable $e) {
                    // The status line is already sent: the app gets a short playlist, so at least record why
                    report($e);

                    throw $e;
                }
            },
            200,
            ['Content-Type' => 'audio/x-mpegurl', 'Content-Disposition' => 'attachment; filename="playlist.m3u"']
        );
    }
}
