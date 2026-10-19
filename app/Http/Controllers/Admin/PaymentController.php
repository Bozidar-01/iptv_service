<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ResellerPayment;
use App\Models\Sale;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Payments (admins and resellers): prices, who has paid for their months, and (admins) what each reseller owes for credits.
 */
class PaymentController extends Controller
{
    protected $sales;

    public function __construct(SaleService $sales)
    {
        $this->sales = $sales;
    }

    public function index(Request $request)
    {
        $actor = $request->user();
        $filters = [
            'status' => in_array($request->query('status'), ['unpaid', 'paid', 'all'], true) ? $request->query('status') : 'unpaid',
            'search' => is_string($request->query('search')) ? trim($request->query('search')) : '',
            'seller' => $actor->isAdmin() && is_string($request->query('seller')) ? $request->query('seller') : '',
        ];

        $visible = $this->sales->visibleTo($actor)
            ->when($filters['seller'] === 'own', fn ($q) => $q->whereNull('owner_id'))
            ->when(ctype_digit($filters['seller']), fn ($q) => $q->where('owner_id', (int) $filters['seller']));
        $sales = (clone $visible)
            ->when($filters['status'] === 'unpaid', fn ($q) => $q->whereNull('paid_at'))
            ->when($filters['status'] === 'paid', fn ($q) => $q->whereNotNull('paid_at'))
            ->when($filters['search'] !== '', fn ($q) => $q->where('customer_name', 'like', '%'.$filters['search'].'%'))
            ->with('owner:id,username')
            ->latest('created_at')->latest('id')
            ->paginate(50)->withQueryString();

        $resellers = $actor->isAdmin() ? User::where('role', 'reseller')->orderBy('username')->get(['id', 'username', 'credit_price', 'price_per_month']) : collect();

        return view('admin.payments.index', [
            'sales' => $sales,
            'filters' => $filters,
            'totals' => $this->sales->totals($visible),
            'settings' => $this->sales->settings(),
            'resellers' => $resellers,
            'balances' => $actor->isAdmin() ? $this->sales->balances($resellers->pluck('id')->all()) : $this->sales->balances([$actor->id]),
            'payments' => ResellerPayment::query()->when(!$actor->isAdmin(), fn ($q) => $q->where('reseller_id', $actor->id))
                ->with('reseller:id,username')->latest('paid_on')->latest('id')->limit(10)->get(),
        ]);
    }

    /** POST payments/sales/{sale}: `paid` 1 or 0. */
    public function paid(Request $request, Sale $sale)
    {
        abort_unless($this->sales->canManage($request->user(), $sale), 403);
        $paid = $request->boolean('paid');

        $this->sales->setPaid($sale, $paid);

        return back()->with('success', $paid ? __(':name marked as paid.', ['name' => $sale->customer_name]) : __(':name marked as not paid.', ['name' => $sale->customer_name]));
    }

    /**
     * POST payments/prices. Admins: currency, their price per month, the default price per credit, and `credit_price[id]` per
     * reseller. Resellers: their own price per month.
     */
    public function prices(Request $request)
    {
        $actor = $request->user();

        if (!$actor->isAdmin()) {
            $data = $request->validate(['price_per_month' => ['nullable', 'numeric', 'min:0', 'max:100000']]);
            $actor->forceFill(['price_per_month' => $data['price_per_month'] ?? null])->save();

            return back()->with('success', __('Prices saved. New sales use them.'));
        }

        $data = $request->validate([
            'currency' => ['required', 'string', 'max:5'],
            'price_per_month' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'credit_price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'credit_prices' => ['nullable', 'array'],
            'credit_prices.*' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        $this->sales->saveSettings([
            'currency' => mb_strtoupper(trim($data['currency'])),
            'price_per_month' => isset($data['price_per_month']) ? (float) $data['price_per_month'] : null,
            'credit_price' => isset($data['credit_price']) ? (float) $data['credit_price'] : null,
        ]);
        foreach (User::where('role', 'reseller')->whereIn('id', array_keys($data['credit_prices'] ?? []))->get() as $reseller) {
            $price = $data['credit_prices'][$reseller->id] ?? null;
            $reseller->forceFill(['credit_price' => $price === null || $price === '' ? null : (float) $price])->save();
        }

        return back()->with('success', __('Prices saved. New sales use them.'));
    }

    /** POST payments/resellers/{user}: an admin records money a reseller paid. */
    public function payment(Request $request, User $user)
    {
        abort_unless($user->isReseller(), 404);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:10000000'],
            'note' => ['nullable', 'string', 'max:255'],
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $this->sales->recordPayment($request->user(), $user, (float) $data['amount'], $data['note'] ?? null, isset($data['paid_on']) ? Carbon::parse($data['paid_on']) : now());

        return back()->with('success', __('Payment from :name recorded.', ['name' => $user->username]));
    }
}
