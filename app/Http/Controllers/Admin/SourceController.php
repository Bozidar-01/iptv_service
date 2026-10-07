<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bouquet;
use App\Models\Source;
use App\Services\SourceService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SourceController extends Controller
{
    protected $sourceService;

    public function __construct(SourceService $sourceService)
    {
        $this->sourceService = $sourceService;
    }

    public function index()
    {
        $sources = $this->sourceService->getAllSources();

        return view('admin.sources.index', compact('sources'));
    }

    public function create()
    {
        $bouquets = Bouquet::orderBy('name')->get();
        // Suggested shared secret for a new receiver relay
        $newSecret = Str::random(40);

        return view('admin.sources.create', compact('bouquets', 'newSecret'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);

        $this->sourceService->createSource($data, $request->file('file'));

        return redirect()->route('admin.sources.index')->with('success', __('Source added. Channels are being imported in the background.'));
    }

    public function edit(Source $source)
    {
        $bouquets = Bouquet::orderBy('name')->get();

        return view('admin.sources.edit', compact('source', 'bouquets'));
    }

    public function update(Request $request, Source $source)
    {
        $data = $this->validated($request, $source);

        $this->sourceService->updateSource($source, $data, $request->file('file'));

        return redirect()->route('admin.sources.index')->with('success', __('Source updated. Sync it to apply changes to its channels.'));
    }

    public function destroy(Source $source)
    {
        $this->sourceService->deleteSource($source);

        return redirect()->route('admin.sources.index')->with('success', __('Source and its channels deleted.'));
    }

    public function sync(Source $source)
    {
        if (!$this->sourceService->queueSync($source)) {
            return back()->with('error', __('A sync for this source is already in progress.'));
        }

        return back()->with('success', __('Sync started for :name.', ['name' => $source->name]));
    }

    private function validated(Request $request, ?Source $source): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['url', 'file', 'receiver'])],
            'url' => ['nullable', 'required_if:type,url', 'url', 'max:2048'],
            'relay_url' => ['nullable', 'required_if:type,receiver', 'url', 'max:2048'],
            // Required for a new receiver; blank on edit keeps the current secret
            'relay_secret' => [Rule::requiredIf($request->input('type') === 'receiver' && !$source?->relay_secret), 'nullable', 'string', 'min:16', 'max:255'],
            'transcode' => ['boolean'],
            // A file is required for new file sources, optional when replacing an existing one
            'file' => [
                Rule::requiredIf($request->input('type') === 'file' && !$source?->file_path),
                'nullable', 'file', 'extensions:m3u,m3u8,txt', 'max:51200',
            ],
            'bouquet_id' => ['nullable', function ($attribute, $value, $fail) {
                if ($value !== 'new' && !Bouquet::whereKey($value)->exists()) {
                    $fail(__('The selected bouquet is invalid.'));
                }
            }],
            'skip_vod' => ['boolean'],
            'auto_sync' => ['boolean'],
            'check_streams' => ['boolean'],
            'alert_on_down' => ['boolean'],
        ]);

        unset($data['file']);
        $data['skip_vod'] = $request->boolean('skip_vod');
        $data['auto_sync'] = $request->boolean('auto_sync');
        $data['check_streams'] = $request->boolean('check_streams');
        $data['alert_on_down'] = $request->boolean('alert_on_down');
        $data['transcode'] = $request->boolean('transcode');
        if ($data['type'] === 'file') {
            $data['url'] = null;
        }
        if ($data['type'] === 'receiver') {
            $data['url'] = rtrim($data['relay_url'], '/');
            // Checking receiver channels would tune the receiver; the relay is checked instead
            $data['check_streams'] = false;
            $data['skip_vod'] = false;
        }
        if (blank($data['relay_secret'] ?? null) || $data['type'] !== 'receiver') {
            unset($data['relay_secret']);
        }
        unset($data['relay_url']);

        return $data;
    }
}
