<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActiveSession;
use App\Models\Bouquet;
use App\Models\User;
use App\Services\StreamService;
use App\Services\UserService;
use Illuminate\Http\Request;

class UserController extends Controller
{
    protected $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function index(Request $request)
    {
        $filters = $request->only(['search', 'status']);
        $users = $this->userService->getAllUsers($filters);

        return view('admin.users.index', compact('users', 'filters'));
    }

    public function show(Request $request, User $user)
    {
        $user->load('bouquets')->loadCount(['activeSessions' => fn ($query) => $query->active()]);
        $devices = $user->activeSessions()->active()->with('channel')->orderByDesc('last_activity_at')->get();
        $playlistUrl = route('playlist', ['token' => $user->playlist_token]);
        $isLocalHost = in_array($request->getHost(), ['localhost', '127.0.0.1', '::1'], true);
        $channelCount = $this->userService->channelCount($user);
        $recentLogs = $user->streamLogs()->with('channel')->orderByDesc('started_at')->limit(10)->get();
        // Xtream Codes login: apps want the server address without a path
        $server = [
            'url' => $request->getSchemeAndHttpHost(),
            'host' => $request->getHost(),
            'port' => $request->getPort(),
        ];

        return view('admin.users.show', compact('user', 'playlistUrl', 'isLocalHost', 'channelCount', 'recentLogs', 'server', 'devices'));
    }

    public function disconnectDevice(User $user, ActiveSession $session, StreamService $streams)
    {
        // The session must belong to this user, or the URL could end someone else's
        abort_unless($session->user_id === $user->id, 404);

        $streams->disconnect($session);

        return back()->with('success', __('Device disconnected. It can not start a stream for :minutes minutes.', ['minutes' => StreamService::DEVICE_BLOCK_MINUTES]));
    }

    public function disconnectAllDevices(User $user, StreamService $streams)
    {
        $count = $streams->disconnectAll($user);

        return back()->with('success', $count
            ? trans_choice(':count device disconnected. It can not start a stream for :minutes minutes.|:count devices disconnected. They can not start a stream for :minutes minutes.', $count, ['count' => $count, 'minutes' => StreamService::DEVICE_BLOCK_MINUTES])
            : __('Nobody is watching right now.'));
    }

    public function regeneratePlaylistToken(User $user)
    {
        $user->regeneratePlaylistToken();

        return back()->with('success', __('New playlist link generated. The old link no longer works.'));
    }

    public function create()
    {
        $bouquets = Bouquet::withCount('channels')->orderBy('name')->get();

        return view('admin.users.create', compact('bouquets'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string|max:255|unique:users',
            'password' => 'required|string|min:6',
            'status' => 'required|in:active,disabled',
            'role' => 'required|in:admin,user',
            'expiration_date' => 'nullable|date',
            'max_connections' => 'required|integer|min:1',
            'bouquets' => 'nullable|array',
            'bouquets.*' => 'exists:bouquets,id',
        ]);

        $user = $this->userService->createUser($data);

        return redirect()->route('admin.users.show', $user)->with('success', __('User created. Share the playlist link below.'));
    }

    public function edit(User $user)
    {
        $bouquets = Bouquet::withCount('channels')->orderBy('name')->get();
        $selectedBouquets = $user->bouquets->pluck('id')->toArray();

        return view('admin.users.edit', compact('user', 'bouquets', 'selectedBouquets'));
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'username' => 'required|string|max:255|unique:users,username,'.$user->id,
            'password' => 'nullable|string|min:6',
            'status' => 'required|in:active,disabled',
            'role' => 'required|in:admin,user',
            'expiration_date' => 'nullable|date',
            'max_connections' => 'required|integer|min:1',
            'bouquets' => 'nullable|array',
            'bouquets.*' => 'exists:bouquets,id',
        ]);

        $data['bouquets'] = $data['bouquets'] ?? []; // unchecking every bouquet must clear them
        $this->userService->updateUser($user, $data);

        return redirect()->route('admin.users.show', $user)->with('success', __('User updated successfully.'));
    }

    public function destroy(User $user)
    {
        if ($user->is(auth()->user())) {
            return back()->with('error', __("You can't delete your own account."));
        }

        $this->userService->deleteUser($user);

        return redirect()->route('admin.users.index')->with('success', __('User deleted successfully.'));
    }

    public function toggleStatus(User $user)
    {
        $this->userService->toggleStatus($user);

        return back()->with('success', __('User status updated.'));
    }
}
