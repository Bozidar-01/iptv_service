<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncEpgSource;
use App\Models\AuditLog;
use App\Models\EpgSource;
use App\Models\Source;
use App\Services\AuditService;
use App\Services\ReceiverStatusService;
use App\Services\ReceiverTidyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * One satellite receiver: what its relay says right now, and tidying its channel list (admins).
 */
class ReceiverController extends Controller
{
    protected $status;

    protected $tidy;

    protected $audit;

    public function __construct(ReceiverStatusService $status, ReceiverTidyService $tidy, AuditService $audit)
    {
        $this->status = $status;
        $this->tidy = $tidy;
        $this->audit = $audit;
    }

    public function show(Source $source)
    {
        abort_unless($source->isReceiver(), 404);

        return view('admin.receiver.show', [
            'source' => $source,
            'status' => $this->status->status($source),
            'counts' => $this->tidy->counts($source),
            'guide' => EpgSource::where('receiver_source_id', $source->id)->first(),
        ]);
    }

    /**
     * Use the receiver's own TV guide (relay 1.3+ /epg.xml): add it as a guide source once, and import it now.
     */
    public function guide(Source $source)
    {
        abort_unless($source->isReceiver(), 404);

        $guide = EpgSource::firstOrCreate(
            ['receiver_source_id' => $source->id],
            ['name' => __(':name (receiver guide)', ['name' => $source->name]), 'url' => rtrim((string) $source->url, '/').'/epg.xml']
        );
        // Already waiting for an import: one is enough
        if (!$guide->isBusy()) {
            $guide->update(['status' => Source::STATUS_QUEUED]);
            SyncEpgSource::dispatch($guide);
        }

        return back()->with('success', __('Importing the receiver\'s TV guide. It shows on the TV guide page in a minute.'));
    }

    public function refresh(Source $source)
    {
        abort_unless($source->isReceiver(), 404);
        Cache::forget("receiver-status:{$source->id}");

        return redirect()->route('admin.receiver.show', $source);
    }

    public function tidy(Request $request, Source $source)
    {
        abort_unless($source->isReceiver(), 404);
        $data = $request->validate(['criteria' => ['required', 'array', 'min:1'], 'criteria.*' => ['in:'.implode(',', ReceiverTidyService::CRITERIA)]], ['criteria.required' => __('Choose at least one kind of channel.')]);

        $count = $this->tidy->apply($source, $data['criteria']);

        if ($count > 0) {
            $this->audit->record(AuditLog::CHANNELS_TIDIED, ['type' => 'source', 'id' => $source->id, 'name' => $source->name], ['count' => $count]);
        }

        return back()->with('success', trans_choice(':count channel switched off.|:count channels switched off.', $count, ['count' => number_format($count)]));
    }
}
