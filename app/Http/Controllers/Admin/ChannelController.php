<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Services\ChannelService;
use Illuminate\Http\Request;

class ChannelController extends Controller
{
    protected $channelService;

    public function __construct(ChannelService $channelService)
    {
        $this->channelService = $channelService;
    }

    public function index()
    {
        $channels = $this->channelService->getAllChannels();
        return view('admin.channels.index', compact('channels'));
    }

    public function create()
    {
        $categories = $this->channelService->getAllCategories();
        return view('admin.channels.create', compact('categories'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'stream_url' => 'required|url',
            'logo_url' => 'nullable|url',
            'category_id' => 'required|exists:categories,id',
            'is_active' => 'boolean',
        ]);

        $this->channelService->createChannel($data);

        return redirect()->route('admin.channels.index')->with('success', 'Channel created successfully.');
    }

    public function edit(Channel $channel)
    {
        $categories = $this->channelService->getAllCategories();
        return view('admin.channels.edit', compact('channel', 'categories'));
    }

    public function update(Request $request, Channel $channel)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'stream_url' => 'required|url',
            'logo_url' => 'nullable|url',
            'category_id' => 'required|exists:categories,id',
            'is_active' => 'boolean',
        ]);

        $this->channelService->updateChannel($channel, $data);

        return redirect()->route('admin.channels.index')->with('success', 'Channel updated successfully.');
    }

    public function destroy(Channel $channel)
    {
        $this->channelService->deleteChannel($channel);

        return redirect()->route('admin.channels.index')->with('success', 'Channel deleted successfully.');
    }
}
