<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\InsufficientCredits;
use App\Exceptions\ResellerLimitReached;
use App\Http\Controllers\Controller;
use App\Models\ActiveSession;
use App\Models\AuditLog;
use App\Models\Bouquet;
use App\Models\CreditTransaction;
use App\Models\DeniedPlay;
use App\Models\Sale;
use App\Models\StreamAlert;
use App\Models\User;
use App\Services\AuditService;
use App\Services\CreditService;
use App\Services\SaleService;
use App\Services\SharingDetectionService;
use App\Services\StreamService;
use App\Services\UserBulkService;
use App\Services\UserService;
use App\Support\Credits;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /** Lengths the Renew button offers, in months (1 month = 1 credit). */
    public const RENEW_MONTHS = [1, 3, 6, 12];

    protected $userService;

    protected $credits;

    protected $audit;

    protected $sharing;

    protected $sales;

    public function __construct(UserService $userService, CreditService $credits, AuditService $audit, SharingDetectionService $sharing, SaleService $sales)
    {
        $this->sales = $sales;
        $this->sharing = $sharing;
        $this->userService = $userService;
        $this->credits = $credits;
        $this->audit = $audit;
    }

    public function index(Request $request)
    {
        // Only plain values: ?search[]=x would otherwise reach the query as an array
        $filters = array_filter($request->only(['search', 'status', 'reseller']), 'is_scalar');
        $users = $this->userService->getAllUsers($filters, $request->user());
        // Bouquets the actor can give or take in a bulk action: all for an admin, the ones they hold for a reseller
        $bulkBouquets = ($request->user()->isAdmin() ? Bouquet::query() : $request->user()->bouquets())->orderBy('bouquets.name')->get(['bouquets.id', 'bouquets.name']);

        return view('admin.users.index', compact('users', 'filters', 'bulkBouquets'));
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
        $loginMessage = $this->userService->loginMessage($user, $request->getSchemeAndHttpHost(), $playlistUrl, route('me', ['token' => $user->playlist_token]));
        $refused = DeniedPlay::with('channel:id,name')->where('user_id', $user->id)->latest('id')->limit(5)->get();
        $addresses = $this->sharing->addresses($user);
        $recentAddressCount = $this->sharing->recentAddressCount($user);
        $recentLogs = $user->streamLogs()->with('channel')->orderByDesc('started_at')->limit(10)->get();
        // Xtream Codes login: apps want the server address without a path
        $server = [
            'url' => $request->getSchemeAndHttpHost(),
            'host' => $request->getHost(),
            'port' => $request->getPort(),
        ];

        return view('admin.users.show', compact('user', 'playlistUrl', 'isLocalHost', 'channelCount', 'recentLogs', 'server', 'devices', 'loginMessage', 'addresses', 'recentAddressCount', 'refused'));
    }

    /**
     * Do the same thing to many subscribers: the selected ones, or everyone matching the list filters.
     */
    public function bulk(Request $request, UserBulkService $bulk)
    {
        $actor = $request->user();
        $bouquets = $actor->isAdmin() ? Bouquet::pluck('id')->all() : $actor->bouquets()->pluck('bouquets.id')->all();

        $data = $request->validate([
            'action' => ['required', Rule::in(UserBulkService::ACTIONS)],
            'scope' => ['required', Rule::in(['selected', 'filtered'])],
            'ids' => ['required_if:scope,selected', 'array'],
            'ids.*' => ['integer'],
            'filters' => ['nullable', 'array'],
            'months' => ['required_if:action,extend', 'nullable', Rule::in(self::RENEW_MONTHS)],
            'bouquet_id' => ['required_if:action,add_bouquet,remove_bouquet', 'nullable', Rule::in($bouquets)],
        ], [
            'ids.required_if' => __('Select at least one user.'),
            'bouquet_id.required_if' => __('Choose a bouquet.'),
            'months.required_if' => __('Choose how long.'),
        ]);

        $query = $data['scope'] === 'filtered'
            ? $this->userService->filteredQuery(array_filter($data['filters'] ?? [], 'is_scalar'), $actor)
            : User::visibleTo($actor)->whereIn('id', $data['ids']);

        try {
            $result = $bulk->apply($actor, $data['action'], $query, ['months' => (int) ($data['months'] ?? 0), 'bouquet_id' => (int) ($data['bouquet_id'] ?? 0)]);
        } catch (InsufficientCredits $e) {
            return back()->with('error', __('Not enough credits: this needs :needed, you have :balance.', ['needed' => $e->needed, 'balance' => $e->balance]));
        }

        if ($result['changed'] > 0) {
            $this->audit->record(AuditLog::USERS_BULK, null, ['action' => $data['action'], 'count' => $result['changed'], 'credits_spent' => $result['spent']]);
        }

        $message = [
            'enable' => ':count user enabled.|:count users enabled.',
            'disable' => ':count user disabled.|:count users disabled.',
            'add_bouquet' => ':count user got the bouquet.|:count users got the bouquet.',
            'remove_bouquet' => ':count user lost the bouquet.|:count users lost the bouquet.',
            'extend' => ':count user renewed.|:count users renewed.',
            'delete' => ':count user deleted.|:count users deleted.',
        ][$data['action']];

        return back()->with('success', $this->withCreditNote(trans_choice($message, $result['changed'], ['count' => number_format($result['changed'])]), $actor, $result['spent']));
    }

    /**
     * Renew a subscriber: move the end date later by the chosen months, from the current end date (or from now when
     * it already ended). An admin renews for free; a reseller with credits pays 1 per month. A trial becomes a normal user.
     */
    public function renew(Request $request, User $user)
    {
        $actor = $request->user();
        $months = (int) $request->validate(['months' => ['required', Rule::in(self::RENEW_MONTHS)]])['months'];

        abort_unless($user->role === 'user', 404);

        if (!$user->expiration_date) {
            return back()->with('error', __('This user never expires, so there is nothing to renew.'));
        }

        $before = $this->audit->snapshot($user);

        try {
            $spent = DB::transaction(function () use ($actor, $user, $months) {
                $spent = 0;
                $before = $user->expiration_date?->copy();

                if ($actor->isReseller() && User::whereKey($actor->id)->lockForUpdate()->firstOrFail()->usesCredits()) {
                    $spent = Credits::months($user->expiration_date, $this->userService->renewedUntil($user, $months));
                }

                $this->userService->renew($user, $months);
                $this->sales->record($user->fresh(), $before, $user->fresh()->expiration_date, Sale::RENEWAL, $actor);

                if ($spent > 0) {
                    $this->credits->charge($actor, $spent, CreditTransaction::EXTENDED, $user);
                }

                return $spent;
            });
        } catch (InsufficientCredits $e) {
            return back()->with('error', __('Not enough credits: this needs :needed, you have :balance.', ['needed' => $e->needed, 'balance' => $e->balance]));
        }

        // Whatever asked for or warned about this renewal is dealt with
        StreamAlert::where('user_id', $user->id)->whereIn('type', StreamAlert::SUBSCRIPTION_TYPES)->whereNull('read_at')->update(['read_at' => now()]);

        $this->audit->record(AuditLog::USER_RENEWED, $user, ['months' => $months, 'credits_spent' => $spent] + $this->audit->changes($before, $this->audit->snapshot($user)));

        return back()->with('success', $this->withCreditNote(trans_choice('Renewed for :count month. Now valid until :date.|Renewed for :count months. Now valid until :date.', $months, ['count' => $months, 'date' => $user->fresh()->expiration_date->translatedFormat('j F Y')]), $actor, $spent));
    }

    public function disconnectDevice(User $user, ActiveSession $session, StreamService $streams)
    {
        // The session must belong to this user, or the URL could end someone else's
        abort_unless($session->user_id === $user->id, 404);

        $streams->disconnect($session);
        $this->audit->record(AuditLog::USER_DISCONNECTED, $user, ['devices' => 1]);

        return back()->with('success', __('Device disconnected. It can not start a stream for :minutes minutes.', ['minutes' => StreamService::DEVICE_BLOCK_MINUTES]));
    }

    public function disconnectAllDevices(User $user, StreamService $streams)
    {
        $count = $streams->disconnectAll($user);
        if ($count) {
            $this->audit->record(AuditLog::USER_DISCONNECTED, $user, ['devices' => $count]);
        }

        return back()->with('success', $count
            ? trans_choice(':count device disconnected. It can not start a stream for :minutes minutes.|:count devices disconnected. They can not start a stream for :minutes minutes.', $count, ['count' => $count, 'minutes' => StreamService::DEVICE_BLOCK_MINUTES])
            : __('Nobody is watching right now.'));
    }

    public function regeneratePlaylistToken(User $user)
    {
        $user->regeneratePlaylistToken();
        $this->audit->record(AuditLog::USER_LINK, $user);

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
        $initialCredits = $data['initial_credits'] ?? null;
        unset($data['initial_credits']);
        $data = $this->applyRestrictions($actor, $data);

        try {
            // The reseller's limit and credits are checked and spent under a lock on their row: parallel requests
            // can't all pass the check, and a user that can't be paid for is not created at all
            [$user, $spent] = DB::transaction(function () use ($actor, $data, $initialCredits) {
                $spent = 0;

                if ($actor->isReseller()) {
                    $reseller = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                    if (!$reseller->canAddCustomer()) {
                        throw new ResellerLimitReached;
                    }
                    $spent = $reseller->usesCredits() ? Credits::months(null, Carbon::parse($data['expiration_date'])) : 0;
                }

                $user = $this->userService->createUser($data);
                $this->sales->record($user, null, $user->expiration_date, Sale::NEW, $actor);

                if ($spent > 0) {
                    $this->credits->charge($actor, $spent, CreditTransaction::NEW_SUBSCRIPTION, $user);
                }

                // An admin can start a new reseller with credits (0 switches the credit limit on with nothing to spend)
                if ($actor->isAdmin() && $user->isReseller() && $initialCredits !== null) {
                    $this->credits->adjust($actor, $user, (int) $initialCredits, __('Starting credits'));
                }

                return [$user, $spent];
            });
        } catch (ResellerLimitReached) {
            return back()->withInput()->with('error', __('You have reached your limit of :count users.', ['count' => $actor->max_customers]));
        } catch (InsufficientCredits $e) {
            return back()->withInput()->with('error', __('Not enough credits: this needs :needed, you have :balance.', ['needed' => $e->needed, 'balance' => $e->balance]));
        }

        $snapshot = $this->audit->snapshot($user);
        $this->audit->record(AuditLog::USER_CREATED, $user, [
            'set' => Arr::only($snapshot, ['role', 'reseller', 'expires', 'streams']),
            'bouquets' => $snapshot['bouquets'],
            'credits_spent' => $spent,
        ]);

        return redirect()->route('admin.users.show', $user)->with('success', $this->withCreditNote(__('User created. Share the playlist link below.'), $actor, $spent));
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
        $before = $this->audit->snapshot($user);

        try {
            $spent = DB::transaction(function () use ($actor, $user, $data) {
                $spent = 0;
                $before = $user->expiration_date?->copy();

                if ($actor->isReseller() && User::whereKey($actor->id)->lockForUpdate()->firstOrFail()->usesCredits()) {
                    // Only the time added beyond what the user already has is paid for
                    $spent = Credits::months($user->expiration_date, Carbon::parse($data['expiration_date']));
                }

                $this->userService->updateUser($user, $data);
                // A later end date is months sold (as a new subscription when it had none)
                $after = $user->fresh();
                $this->sales->record($after, $before, $after->expiration_date, $before ? Sale::RENEWAL : Sale::NEW, $actor);

                if ($spent > 0) {
                    $this->credits->charge($actor, $spent, CreditTransaction::EXTENDED, $user);
                }

                return $spent;
            });
        } catch (InsufficientCredits $e) {
            return back()->withInput()->with('error', __('Not enough credits: this needs :needed, you have :balance.', ['needed' => $e->needed, 'balance' => $e->balance]));
        }

        $changes = $this->audit->changes($before, $this->audit->snapshot($user)) + ['password' => !empty($data['password']), 'credits_spent' => $spent];
        if (array_filter($changes)) {
            $this->audit->record(AuditLog::USER_UPDATED, $user, $changes);
        }

        return redirect()->route('admin.users.show', $user)->with('success', $this->withCreditNote(__('User updated successfully.'), $actor, $spent));
    }

    /**
     * "…  3 credits used, 7 left." after a reseller spent credits.
     */
    private function withCreditNote(string $message, User $actor, int $spent): string
    {
        if ($spent <= 0 || !$actor->isReseller()) {
            return $message;
        }

        return $message.' '.trans_choice(':count credit used, :left left.|:count credits used, :left left.', $spent, ['count' => $spent, 'left' => $actor->fresh()->credits]);
    }

    public function destroy(User $user)
    {
        if ($user->is(auth()->user())) {
            return back()->with('error', __("You can't delete your own account."));
        }

        if ($user->customers()->exists()) {
            return back()->with('error', __('This reseller still has users. Move or delete them first.'));
        }

        $this->audit->record(AuditLog::USER_DELETED, $user, ['set' => ['role' => $user->role, 'reseller' => $user->owner?->username]]);
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
            // With credits a reseller must give an end date: it is what the credits are spent on
            'expiration_date' => $actor->usesCredits() ? 'required|date' : 'nullable|date',
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
                // Only when creating a reseller: credits to start with (empty = no credit limit)
                'initial_credits' => $user ? 'prohibited' : 'nullable|integer|min:0|max:1000000',
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
        $this->audit->record($user->status === 'active' ? AuditLog::USER_ENABLED : AuditLog::USER_DISABLED, $user);

        return back()->with('success', __('User status updated.'));
    }
}
