<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ChannelHideService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Which channels of the bouquets a user (or all of an owner's users) does not get. Admins and resellers.
 * A reseller only ever works with the bouquets they hold and with their own customers ({user} is scoped).
 */
class HiddenChannelController extends Controller
{
    protected $hide;

    public function __construct(ChannelHideService $hide)
    {
        $this->hide = $hide;
    }

    /** The channels of my bouquets; hide them for all my users. */
    public function index(Request $request)
    {
        $actor = $request->user();
        $owner = $this->hide->ownerKey($actor);

        return $this->page($request, $this->hide->bouquetsFor($actor), $owner, null, [
            'heading' => __('Hide channels'),
            'intro' => __('The channels in your bouquets. Hide the ones you do not want your users to get: the bouquet itself does not change, so nobody else is affected.'),
            'action' => route('admin.hide-channels.bulk'),
            'hideLabel' => __('Hide for all my users'),
            'showLabel' => __('Show again for my users'),
        ]);
    }

    public function bulk(Request $request)
    {
        $actor = $request->user();
        $owner = $this->hide->ownerKey($actor);
        $offered = $this->hide->channelsIn($this->hide->bouquetsFor($actor));

        return $this->apply($request, $offered, $owner, null);
    }

    /** The channels one user gets; hide them for this user alone. */
    public function user(Request $request, User $user)
    {
        $bouquets = $this->hide->bouquetsFor($request->user(), $user);

        return $this->page($request, $bouquets, $user->owner_id, $user, [
            'heading' => __('Channels of :name', ['name' => $user->username]),
            'intro' => __('The channels in this user\'s bouquets. Hide the ones this user should not get: the bouquet itself does not change.'),
            'action' => route('admin.users.channels.bulk', $user),
            'hideLabel' => __('Hide for :name', ['name' => $user->username]),
            'showLabel' => __('Show again for :name', ['name' => $user->username]),
            'back' => $user,
        ]);
    }

    public function userBulk(Request $request, User $user)
    {
        $offered = $this->hide->channelsIn($this->hide->bouquetsFor($request->user(), $user));

        return $this->apply($request, $offered, $user->owner_id, $user);
    }

    private function page(Request $request, $bouquets, ?int $owner, ?User $subject, array $view)
    {
        $filters = $request->only(['search', 'category', 'bouquet', 'state']);
        $offered = $this->hide->channelsIn($bouquets);
        $bouquetList = (clone $bouquets)->orderBy('bouquets.name')->get(['bouquets.id', 'bouquets.name']);
        // Only bouquets of this list can be filtered on
        if (!empty($filters['bouquet']) && !$bouquetList->contains('id', (int) $filters['bouquet'])) {
            unset($filters['bouquet']);
        }

        $channels = $this->hide->filter(clone $offered, $filters, $owner, $subject)
            ->with(['category:id,name', 'bouquets' => fn ($q) => $q->whereIn('bouquets.id', $bouquetList->pluck('id'))->select('bouquets.id', 'bouquets.name')])
            ->orderBy('channels.name')
            ->orderBy('channels.id')
            ->paginate(50)
            ->withQueryString();

        $hidden = $this->hide->hiddenAmong($channels->pluck('id')->all(), $owner, $subject);

        return view('admin.hidden-channels.index', $view + [
            'channels' => $channels,
            'filters' => $filters,
            'bouquets' => $bouquetList,
            'categories' => $this->hide->categoriesOf($offered),
            'hiddenByOwner' => $hidden['owner'],
            'hiddenForUser' => $hidden['user'],
            'subject' => $subject,
            'ownerIsMe' => $request->user()->isAdmin() ? $owner === null : $owner === $request->user()->id,
            'ownerName' => $subject?->owner?->username,
            'total' => (clone $offered)->count(),
            'hiddenTotal' => $this->hide->filter(clone $offered, ['state' => 'hidden'], $owner, $subject)->count(),
        ]);
    }

    private function apply(Request $request, Builder $offered, ?int $owner, ?User $subject)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(ChannelHideService::ACTIONS)],
            'scope' => ['required', Rule::in(['selected', 'filtered'])],
            'ids' => ['required_if:scope,selected', 'array'],
            'ids.*' => ['integer'],
            'filters' => ['nullable', 'array'],
        ], ['ids.required_if' => __('Select at least one channel.')]);

        // Always inside the channels this person may work with: ids from elsewhere are ignored
        $channels = $data['scope'] === 'filtered'
            ? $this->hide->filter($offered, $data['filters'] ?? [], $owner, $subject)
            : $offered->whereIn('channels.id', $data['ids']);

        $count = match (true) {
            $data['action'] === 'hide' && $subject !== null => $this->hide->hideForUser($subject, $channels),
            $data['action'] === 'hide' => $this->hide->hideForOwner($owner, $channels),
            $subject !== null => $this->hide->showForUser($subject, $channels),
            default => $this->hide->showForOwner($owner, $channels),
        };

        $message = $data['action'] === 'hide'
            ? ':count channel hidden.|:count channels hidden.'
            : ':count channel shown again.|:count channels shown again.';

        return back()->with('success', trans_choice($message, $count, ['count' => number_format($count)]));
    }
}
