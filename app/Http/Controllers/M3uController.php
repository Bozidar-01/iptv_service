<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\EpgService;
use App\Services\TokenService;
use App\Services\XtreamService;
use App\Support\GzipOutput;
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

        return $this->playlistFor($request, $user, route('xtream.xmltv', ['username' => $user->username, 'password' => $request->input('password')]));
    }

    /**
     * GET /playlist/{token}.m3u: the shareable link shown in the admin panel.
     */
    public function playlist(Request $request, string $token)
    {
        $user = User::where('playlist_token', $token)->first();

        if (!$user) {
            return response('Playlist not found', 404);
        }

        return $this->playlistFor($request, $user, route('epg', ['token' => $token]));
    }

    /**
     * GET /epg/{token}.xml: TV guide for the playlist link (linked from its header).
     */
    public function epg(Request $request, string $token, EpgService $epg)
    {
        $user = User::where('playlist_token', $token)->first();

        if (!$user || !$user->isActive()) {
            return response('Guide not found', 404);
        }

        $gzip = new GzipOutput($request);

        return response()->stream(function () use ($epg, $user, $gzip) {
            $epg->writeXmltv($user, fn ($chunk) => $gzip->write($chunk));
            $gzip->finish();
        }, 200, ['Content-Type' => 'application/xml; charset=utf-8', ...$gzip->headers()]);
    }

    private function playlistFor(Request $request, User $user, ?string $guideUrl = null)
    {
        if ($user->status !== 'active') {
            return response('Account disabled', 403);
        }

        if ($user->isExpired()) {
            return response('Account expired', 403);
        }

        // Each download gets its own device key in the play links, so every app that loads the playlist is its own device
        $tokenValue = $this->tokenService->playbackToken($user)->token.'_'.TokenService::newDeviceKey();

        // Streamed in chunks (compressed when the app accepts it): a big playlist is never held in memory
        $gzip = new GzipOutput($request);

        return response()->stream(
            function () use ($user, $tokenValue, $guideUrl, $gzip) {
                try {
                    $this->tokenService->writeM3u($user, $tokenValue, $guideUrl, function (string $chunk) use ($gzip) {
                        $gzip->write($chunk);
                        flush();
                    });
                    $gzip->finish();
                } catch (\Throwable $e) {
                    // The status line is already sent: the app gets a short playlist, so at least record why
                    report($e);

                    throw $e;
                }
            },
            200,
            ['Content-Type' => 'audio/x-mpegurl', 'Content-Disposition' => 'attachment; filename="playlist.m3u"', ...$gzip->headers()]
        );
    }
}
