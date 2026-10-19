<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Bouquet;
use App\Models\Channel;
use App\Models\Source;
use App\Services\AuditService;
use App\Services\ChannelDuplicateService;
use App\Services\ChannelFailoverService;
use App\Services\ChannelHealthService;
use App\Services\ChannelService;
use App\Services\EpgService;
use App\Services\StreamCheckService;
use App\Services\StreamPreviewService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

class ChannelController extends Controller
{
    protected $channelService;

    protected $audit;

    public function __construct(ChannelService $channelService, AuditService $audit)
    {
        $this->channelService = $channelService;
        $this->audit = $audit;
    }

    public function index(Request $request)
    {
        $filters = $request->only(['search', 'category', 'source', 'bouquet', 'status']);
        $channels = $this->channelService->getAllChannels($filters);
        $categories = $this->channelService->getAllCategories();
        $sources = Source::orderBy('name')->get();
        $bouquets = Bouquet::orderBy('name')->get();
        $trouble = app(ChannelHealthService::class)->troubleCounts($channels->pluck('id')->all());

        return view('admin.channels.index', compact('channels', 'filters', 'categories', 'sources', 'bouquets', 'trouble'));
    }

    public function create()
    {
        $categories = $this->channelService->getAllCategories();

        return view('admin.channels.create', compact('categories'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $this->channelService->createChannel($data);

        return redirect()->route('admin.channels.index')->with('success', __('Channel created successfully.'));
    }

    public function edit(Channel $channel)
    {
        $categories = $this->channelService->getAllCategories();
        $backupSuggestions = $this->channelService->backupSuggestions($channel);
        $nowPlaying = app(EpgService::class)->nowPlaying($channel);
        $health = app(ChannelHealthService::class)->summary($channel);
        $ranked = app(ChannelFailoverService::class)->ranked($channel);

        return view('admin.channels.edit', compact('channel', 'categories', 'backupSuggestions', 'nowPlaying', 'health', 'ranked'));
    }

    public function update(Request $request, Channel $channel)
    {
        $data = $this->validated($request);

        $this->channelService->updateChannel($channel, $data);
        // A guide ID typed by the admin is kept; an empty field goes back to automatic matching
        app(EpgService::class)->matchChannels($channel->source_id);

        return redirect()->route('admin.channels.index')->with('success', __('Channel updated successfully.'));
    }

    public function destroy(Channel $channel)
    {
        $this->audit->record(AuditLog::CHANNELS_DELETED, ['type' => 'channel', 'id' => $channel->id, 'name' => $channel->name], ['count' => 1]);
        $this->channelService->deleteChannel($channel);

        return redirect()->route('admin.channels.index')->with('success', __('Channel deleted successfully.'));
    }

    /**
     * Apply an action to the selected channels, or to every channel matching the current filters.
     */
    public function bulk(Request $request)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(ChannelService::BULK_ACTIONS)],
            'scope' => ['required', Rule::in(['selected', 'filtered'])],
            'ids' => ['required_if:scope,selected', 'array'],
            'ids.*' => ['integer'],
            'bouquet_id' => ['required_if:action,add_to_bouquet,remove_from_bouquet', 'nullable', 'exists:bouquets,id'],
            'filters' => ['nullable', 'array'],
        ], [
            'ids.required_if' => __('Select at least one channel.'),
            'bouquet_id.required_if' => __('Choose a bouquet.'),
        ]);

        $query = $data['scope'] === 'filtered'
            ? $this->channelService->filteredQuery($data['filters'] ?? [])
            : Channel::whereIn('id', $data['ids']);

        $count = $this->channelService->bulkAction($data['action'], $query, $data['bouquet_id'] ?? null);
        if ($data['action'] === 'delete' && $count > 0) {
            $this->audit->record(AuditLog::CHANNELS_DELETED, null, ['count' => $count]);
        }

        $message = [
            'enable' => ':count channel enabled.|:count channels enabled.',
            'disable' => ':count channel disabled.|:count channels disabled.',
            'add_to_bouquet' => ':count channel added to the bouquet.|:count channels added to the bouquet.',
            'remove_from_bouquet' => ':count channel removed from the bouquet.|:count channels removed from the bouquet.',
            'delete' => ':count channel deleted.|:count channels deleted.',
            'alerts_on' => 'Alerts turned on for :count channel.|Alerts turned on for :count channels.',
            'alerts_off' => 'Alerts turned off for :count channel.|Alerts turned off for :count channels.',
        ][$data['action']];

        return back()->with('success', trans_choice($message, $count, ['count' => number_format($count)]));
    }

    /**
     * The same channel listed more than once, with the copy that works and looks best suggested. Nothing changes until applied.
     */
    public function duplicates(Request $request, ChannelDuplicateService $duplicates)
    {
        $search = is_string($request->query('search')) ? $request->query('search') : null;
        $groups = $duplicates->groups($search);
        $perPage = 25;

        $page = new LengthAwarePaginator(
            array_slice($groups, (max(1, (int) $request->query('page', 1)) - 1) * $perPage, $perPage),
            count($groups),
            $perPage,
            max(1, (int) $request->query('page', 1)),
            ['path' => $request->url(), 'query' => array_filter(['search' => $search])]
        );

        return view('admin.channels.duplicates', [
            'groups' => $page,
            'search' => $search,
            'extra' => array_sum(array_map(fn ($g) => count($g['channels']) - 1, $groups)),
        ]);
    }

    /**
     * Switch off the extra copies: the ones on this page with the choices made (`keep[group] = channel id`, 0 = leave the group),
     * or with `scope=all` the suggested copy of every group (limited to the search).
     */
    public function applyDuplicates(Request $request, ChannelDuplicateService $duplicates)
    {
        $data = $request->validate([
            'scope' => ['required', 'in:page,all'],
            'search' => ['nullable', 'string', 'max:255'],
            'keep' => ['required_if:scope,page', 'array'],
            'keep.*' => ['integer', 'min:0'],
        ]);
        $search = $data['search'] ?? null;

        $keep = $data['scope'] === 'all'
            ? collect($duplicates->groups($search))->mapWithKeys(fn ($g) => [$g['key'] => $g['suggested']])->all()
            : array_filter($data['keep'] ?? [], fn ($id) => (int) $id > 0);

        $result = $duplicates->apply($keep, $search);

        if ($result['groups'] > 0) {
            $this->audit->record(AuditLog::CHANNELS_DEDUPLICATED, null, ['groups' => $result['groups'], 'count' => $result['disabled']]);
        }

        $message = trans_choice(':count channel switched off. The best copy of each group stays.|:count channels switched off. The best copy of each group stays.', $result['disabled'], ['count' => number_format($result['disabled'])]);
        if ($result['skipped'] > 0) {
            $message .= ' '.trans_choice(':count group changed in the meantime and was skipped.|:count groups changed in the meantime and were skipped.', $result['skipped'], ['count' => $result['skipped']]);
        }

        return redirect()->route('admin.channels.duplicates', array_filter(['search' => $search]))->with('success', $message);
    }

    /**
     * Queue a stream check of all channels, or of one source's channels.
     */
    public function checkStreams(Request $request, StreamCheckService $checker)
    {
        $data = $request->validate(['source_id' => 'nullable|integer|exists:sources,id']);

        $count = $checker->queueCheck($data['source_id'] ?? null);

        if ($count === null) {
            return back()->with('error', __('A stream check is already running. Try again when it has finished.'));
        }

        return back()->with('success', trans_choice(
            'Checking :count channel. If it does not work, it will be disabled.|Checking :count channels. The ones that do not work will be disabled.',
            $count,
            ['count' => number_format($count)]
        ));
    }

    /**
     * Play a channel's stream (or a signed part of it) through the panel, for the preview player.
     */
    public function preview(Request $request, Channel $channel, StreamPreviewService $preview)
    {
        $target = $preview->target($channel, $request->query('u'), $request->query('s'));

        if ($target === null) {
            abort(403);
        }

        return $preview->proxy($channel, $target);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'stream_url' => 'required|url|max:2048',
            'logo_url' => 'nullable|url|max:2048',
            'category_id' => 'nullable|exists:categories,id',
            'is_active' => 'boolean',
            'alert_on_down' => 'boolean',
            'backup_channel_id' => ['nullable', 'integer', 'exists:channels,id', Rule::notIn([$request->route('channel')?->id])],
            'epg_id' => ['nullable', 'string', 'max:255'],
            'number' => ['nullable', 'integer', 'min:1', 'max:999999'],
        ]);
        $data['epg_id'] = filled($data['epg_id'] ?? null) ? trim($data['epg_id']) : null;
        // An empty number keeps the channel's number (a new channel gets the next free one)
        if (!filled($data['number'] ?? null)) {
            unset($data['number']);
        }
        $data['epg_manual'] = $data['epg_id'] !== null;
        $data['is_active'] = $request->boolean('is_active');
        $data['alert_on_down'] = $request->boolean('alert_on_down');

        return $data;
    }
}
