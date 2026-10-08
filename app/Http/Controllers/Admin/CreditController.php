<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\InsufficientCredits;
use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\CreditService;
use Illuminate\Http\Request;

/**
 * Credits of resellers: the history (a reseller sees their own, an admin everyone's or one reseller's)
 * and, for admins, giving, taking back and un-limiting. Spending happens in UserController.
 */
class CreditController extends Controller
{
    protected $credits;

    public function __construct(CreditService $credits)
    {
        $this->credits = $credits;
    }

    public function index(Request $request)
    {
        $actor = $request->user();
        $resellers = $actor->isAdmin() ? User::where('role', 'reseller')->orderBy('username')->get(['id', 'username', 'role', 'credits']) : collect();

        // A reseller always sees their own; an admin all, or the one chosen
        $reseller = $actor->isReseller() ? $actor : $resellers->firstWhere('id', (int) $request->query('reseller'));

        $transactions = CreditTransaction::with(['user', 'actor', 'customer'])
            ->when($reseller, fn ($query) => $query->where('user_id', $reseller->id))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.credits.index', compact('transactions', 'reseller', 'resellers'));
    }

    public function store(Request $request, User $user)
    {
        abort_unless($user->isReseller(), 404);

        $data = $request->validate([
            'amount' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->credits->adjust($request->user(), $user, (int) $data['amount'], $data['note'] ?? null);
        } catch (InsufficientCredits $e) {
            return back()->withInput()->with('error', __('A balance can not go below 0: :name has :balance credits.', ['name' => $user->username, 'balance' => $e->balance]));
        }

        return back()->with('success', $data['amount'] > 0
            ? trans_choice(':count credit given to :name.|:count credits given to :name.', (int) $data['amount'], ['count' => (int) $data['amount'], 'name' => $user->username])
            : trans_choice(':count credit taken back from :name.|:count credits taken back from :name.', abs((int) $data['amount']), ['count' => abs((int) $data['amount']), 'name' => $user->username]));
    }

    public function unlimited(Request $request, User $user)
    {
        abort_unless($user->isReseller(), 404);

        $this->credits->makeUnlimited($request->user(), $user, $request->input('note'));

        return back()->with('success', __(':name is no longer limited by credits.', ['name' => $user->username]));
    }
}
