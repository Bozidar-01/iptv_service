<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class M3uController extends Controller
{
    protected $tokenService;

    public function __construct(TokenService $tokenService)
    {
        $this->tokenService = $tokenService;
    }

    /**
     * GET /m3u?username=&password=
     */
    public function generate(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('username', $request->username)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response('Invalid credentials', 401);
        }

        return $this->playlistFor($user);
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

        return $this->playlistFor($user);
    }

    private function playlistFor(User $user)
    {
        if ($user->status !== 'active') {
            return response('Account disabled', 403);
        }

        if ($user->isExpired()) {
            return response('Account expired', 403);
        }

        $token = $this->tokenService->playbackToken($user);

        return response($this->tokenService->getM3u($user, $token->token))
            ->header('Content-Type', 'audio/x-mpegurl')
            ->header('Content-Disposition', 'attachment; filename="playlist.m3u"');
    }
}
