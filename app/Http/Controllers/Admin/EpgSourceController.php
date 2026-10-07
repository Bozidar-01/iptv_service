<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncEpgSource;
use App\Models\Channel;
use App\Models\EpgSource;
use App\Models\Source;
use App\Services\EpgService;
use Illuminate\Http\Request;

/**
 * TV guide page: XMLTV guide sources and how many channels have a guide.
 */
class EpgSourceController extends Controller
{
    public function index()
    {
        $sources = EpgSource::orderBy('id')->get();
        $enabled = Channel::where('is_active', true);
        $coverage = [
            'enabled' => (clone $enabled)->count(),
            'matched' => (clone $enabled)->whereNotNull('epg_source_id')->count(),
            'with_programmes' => (clone $enabled)->whereNotNull('epg_source_id')
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('epg_programmes')
                    ->whereColumn('epg_programmes.epg_source_id', 'channels.epg_source_id')
                    ->whereColumn('epg_programmes.xmltv_id', 'channels.epg_id')
                    ->where('epg_programmes.stop', '>', now()))
                ->count(),
        ];

        return view('admin.epg.index', compact('sources', 'coverage'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:2048'],
        ]);

        $source = EpgSource::create($data + ['status' => Source::STATUS_QUEUED]);
        SyncEpgSource::dispatch($source);

        return back()->with('success', __('Guide added. It is being imported in the background.'));
    }

    public function sync(EpgSource $epgSource)
    {
        if ($epgSource->isBusy()) {
            return back()->with('error', __('This guide is already being imported.'));
        }

        $epgSource->update(['status' => Source::STATUS_QUEUED, 'last_error' => null]);
        SyncEpgSource::dispatch($epgSource);

        return back()->with('success', __('Import started for :name.', ['name' => $epgSource->name]));
    }

    public function destroy(EpgSource $epgSource, EpgService $epg)
    {
        $epgSource->delete(); // programmes and guide channels cascade; channels lose the link (nullOnDelete)
        $epg->matchChannels();

        return back()->with('success', __('Guide deleted.'));
    }

    public function match(EpgService $epg)
    {
        $count = $epg->matchChannels();

        return back()->with('success', trans_choice(':count channel has a guide.|:count channels have a guide.', $count, ['count' => number_format($count)]));
    }
}
