<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bouquet;
use App\Models\Channel;
use App\Models\Source;
use App\Services\ChannelService;
use App\Services\StreamCheckService;
use App\Services\StreamPreviewService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChannelController extends Controller
{
    protected $channelService;

    public function __construct(ChannelService $channelService)
    {
        $this->channelService = $channelService;
    }

    public function index(Request $request)
    {
        $filters = $request->only(['search', 'category', 'source', 'bouquet', 'status']);
        $channels = $this->channelService->getAllChannels($filters);
        $categories = $this->channelService->getAllCategories();
        $sources = Source::orderBy('name')->get();
        $bouquets = Bouquet::orderBy('name')->get();

        return view('admin.channels.index', compact('channels', 'filters', 'categories', 'sources', 'bouquets'));
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

        return view('admin.channels.edit', compact('channel', 'categories', 'backupSuggestions'));
    }

    public function update(Request $request, Channel $channel)
    {
        $data = $this->validated($request);

        $this->channelService->updateChannel($channel, $data);

        return redirect()->route('admin.channels.index')->with('success', __('Channel updated successfully.'));
    }

    public function destroy(Channel $channel)
    {
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
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $data['alert_on_down'] = $request->boolean('alert_on_down');

        return $data;
    }
}
