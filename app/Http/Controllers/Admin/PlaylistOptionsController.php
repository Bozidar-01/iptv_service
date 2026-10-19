<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\FillChannelInfo;
use App\Models\Channel;
use App\Services\AnnouncementService;
use App\Services\ChannelCatalogService;
use App\Services\ChannelNumberService;
use App\Services\PlaylistOptionsService;
use Illuminate\Http\Request;

/**
 * What viewers' playlists and apps show: quality in names, only the better copy, channel numbers, logos and guide ids.
 */
class PlaylistOptionsController extends Controller
{
    protected $options;

    public function __construct(PlaylistOptionsService $options)
    {
        $this->options = $options;
    }

    public function index(ChannelCatalogService $catalog, AnnouncementService $announcement)
    {
        return view('admin.playlist-options.index', [
            'settings' => $this->options->settings(),
            'announcement' => $announcement->settings(),
            'announcementShown' => $announcement->current() !== null,
            'catalog' => $catalog->status(),
            'counts' => [
                'channels' => Channel::count(),
                'numbered' => Channel::whereNotNull('number')->count(),
                'better' => Channel::whereNotNull('better_copy_id')->count(),
                'catchup' => Channel::where('is_active', true)->whereNotNull('catchup')->count(),
                'auto_logos' => Channel::whereNull('logo_url')->whereNotNull('auto_logo_url')->count(),
                'auto_ids' => Channel::whereNull('tvg_id')->whereNotNull('auto_tvg_id')->count(),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $request->validate(['quality_in_names' => 'boolean', 'hide_worse_copies' => 'boolean']);

        $this->options->save([
            'quality_in_names' => $request->boolean('quality_in_names'),
            'hide_worse_copies' => $request->boolean('hide_worse_copies'),
        ]);

        return back()->with('success', __('Playlist options saved. Apps show the change when they load the list again.'));
    }

    /** POST playlist-options/announcement: `text` (empty = no message), `until` (last day, optional). */
    public function announcement(Request $request, AnnouncementService $announcement)
    {
        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:'.AnnouncementService::MAX_LENGTH],
            'until' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $announcement->save($data['text'] ?? null, $data['until'] ?? null);

        return back()->with('success', filled($data['text'] ?? null)
            ? __('Message saved. Apps show it at the next login; viewers see it on their page.')
            : __('Message removed.'));
    }

    public function renumber(ChannelNumberService $numbers)
    {
        $count = $numbers->renumber();

        return back()->with('success', trans_choice(':count channel numbered.|:count channels numbered.', $count, ['count' => number_format($count)]));
    }

    public function fillInfo()
    {
        FillChannelInfo::dispatch(download: true);

        return back()->with('success', __('Looking up logos and guide IDs. This takes a few minutes.'));
    }
}
