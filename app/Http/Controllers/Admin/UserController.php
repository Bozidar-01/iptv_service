<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActiveSession;
use App\Models\Bouquet;
use App\Models\User;
use App\Services\StreamService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    protected $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function index(Request $request)
    {
        // Only plain values: ?search[]=x would otherwise reach the query as an array
        $filters = array_filter($request->only(['search', 'status', 'reseller']), 'is_scalar');
        $users = $this->userService->getAllUsers($filters, $request->user());

        return view('admin.users.index', compact('users', 'filters'));
    }

    public function show(Request $request, User $user)
    {
        $user->load(['bouquets', 'owner']);
        // A reseller sees only the bouquets they hold; the ones the admin gave this user stay unnamed
        if ($request->user()->isReseller()) {
            $held = $request->user()->bouquets()->pluck('bouquets.id');
            $user->setRelation('bouquets', $user->bouquets->whereIn('id', $held)->values());
        }
        $user->loadCount([
            'customers',
            'activeSessions' => fn ($query) => $query->active(),
        ]);
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

    public function create(Request $request)
    {
        return view('admin.users.create', $this->formData($request->user()));
    }

    public function store(Request $request)
    {
        $actor = $request->user();

        $data = $request->validate($this->rules($actor));
        $data = $this->applyRestrictions($actor, $data);

        // The limit is checked and the user created under a lock on the reseller's row, so parallel
        // requests can't all pass the check before any of them is saved
        $user = DB::transaction(function () use ($actor, $data) {
            if ($actor->isReseller() && !User::whereKey($actor->id)->lockForUpdate()->firstOrFail()->canAddCustomer()) {
                return null;
            }

            return $this->userService->createUser($data);
        });

        if (!$user) {
            return back()->withInput()->with('error', __('You have reached your limit of :count users.', ['count' => $actor->max_customers]));
        }

        return redirect()->route('admin.users.show', $user)->with('success', __('User created. Share the playlist link below.'));
    }

    public function edit(Request $request, User $user)
    {
        return view('admin.users.edit', $this->formData($request->user()) + [
            'user' => $user,
            'selectedBouquets' => $user->bouquets->pluck('id')->toArray(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $actor = $request->user();
        $data = $request->validate($this->rules($actor, $user));

        if ($actor->isAdmin()) {
            // An admin can't demote themselves, and a reseller with customers stays a reseller
            if ($user->is($actor) && ($data['role'] ?? 'admin') !== 'admin') {
                return back()->withInput()->with('error', __("You can't change your own role."));
            }
            if ($user->isReseller() && ($data['role'] ?? 'reseller') !== 'reseller' && $user->customers()->exists()) {
                return back()->withInput()->with('error', __('This reseller still has users. Move or delete them first.'));
            }
        }

        $data = $this->applyRestrictions($actor, $data, $user);
        $data['bouquets'] = $data['bouquets'] ?? []; // unchecking every bouquet must clear them
        $this->userService->updateUser($user, $data);

        return redirect()->route('admin.users.show', $user)->with('success', __('User updated successfully.'));
    }

    public function destroy(User $user)
    {
        if ($user->is(auth()->user())) {
            return back()->with('error', __("You can't delete your own account."));
        }

        if ($user->customers()->exists()) {
            return back()->with('error', __('This reseller still has users. Move or delete them first.'));
        }

        $this->userService->deleteUser($user);

        return redirect()->route('admin.users.index')->with('success', __('User deleted successfully.'));
    }

    /**
     * What the form offers $actor: all bouquets and the resellers for an admin; for a reseller only the
     * bouquets they were given.
     */
    private function formData(User $actor): array
    {
        $bouquets = $actor->isAdmin() ? Bouquet::query() : $actor->bouquets();

        return [
            'bouquets' => $bouquets->withCount('channels')->orderBy('name')->get(),
            'resellers' => $actor->isAdmin() ? User::where('role', 'reseller')->orderBy('username')->get(['id', 'username']) : collect(),
        ];
    }

    /**
     * Validation rules: an admin sets everything; a reseller can't choose the role or owner, may only hand
     * out the bouquets they have, and can't give more simultaneous streams than their cap.
     */
    private function rules(User $actor, ?User $user = null): array
    {
        $rules = [
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:6'],
            'status' => 'required|in:active,disabled',
            'email' => 'nullable|email|max:255',
            'locale' => ['nullable', Rule::in(array_keys(config('app.locales')))],
            'expiration_date' => 'nullable|date',
            'max_connections' => ['required', 'integer', 'min:1', ...($actor->isReseller() && $actor->max_customer_connections ? ['max:'.$actor->max_customer_connections] : [])],
            'bouquets' => 'nullable|array',
            'bouquets.*' => $actor->isAdmin() ? 'exists:bouquets,id' : Rule::in($actor->bouquets()->pluck('bouquets.id')->all()),
        ];

        if ($actor->isAdmin()) {
            $rules += [
                'role' => 'required|in:admin,reseller,user',
                'owner_id' => ['nullable', Rule::exists('users', 'id')->where('role', 'reseller')],
                'max_customers' => 'nullable|integer|min:1|max:1000000',
                'max_customer_connections' => 'nullable|integer|min:1|max:100',
            ];
        }

        return $rules;
    }

    /**
     * Makes the saved data match what the actor may do, whatever was posted.
     */
    private function applyRestrictions(User $actor, array $data, ?User $user = null): array
    {
        if ($actor->isReseller()) {
            // A reseller's customers are always subscribers of theirs
            $data['role'] = 'user';
            $data['owner_id'] = $actor->id;
            unset($data['max_customers'], $data['max_customer_connections']);

            // Bouquets the reseller can't hand out (given to the customer by the admin) are kept as they are
            if ($user) {
                $allowed = $actor->bouquets()->pluck('bouquets.id')->all();
                $data['bouquets'] = array_values(array_unique(array_merge(
                    $data['bouquets'] ?? [],
                    $user->bouquets()->whereNotIn('bouquets.id', $allowed)->pluck('bouquets.id')->all(),
                )));
            }

            return $data;
        }

        // Only subscribers have a reseller; only resellers have limits
        if (($data['role'] ?? 'user') !== 'user') {
            $data['owner_id'] = null;
        }
        if (($data['role'] ?? 'user') !== 'reseller') {
            $data['max_customers'] = null;
            $data['max_customer_connections'] = null;
        }

        return $data;
    }

    public function toggleStatus(User $user)
    {
        $this->userService->toggleStatus($user);

        return back()->with('success', __('User status updated.'));
    }
}
