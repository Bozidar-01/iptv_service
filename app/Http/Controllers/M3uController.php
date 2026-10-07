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

        if ($user->status !== 'active') {
            return response('Account disabled', 403);
        }

        if ($user->isExpired()) {
            return response('Account expired', 403);
        }

        // Get or generate token
        $token = $user->accessTokens()->where('expires_at', '>', now())->first();
        if (!$token) {
            $token = $this->tokenService->generateToken($user);
        }

        $m3uContent = $this->tokenService->getM3u($user, $token->token);

        return response($m3uContent)
            ->header('Content-Type', 'audio/x-mpegurl')
            ->header('Content-Disposition', 'attachment; filename="playlist.m3u"');
    }
}
