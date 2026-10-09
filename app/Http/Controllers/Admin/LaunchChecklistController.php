<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\LaunchChecklistService;
use Illuminate\Http\Request;

class LaunchChecklistController extends Controller
{
    protected $checklist;

    public function __construct(LaunchChecklistService $checklist)
    {
        $this->checklist = $checklist;
    }

    public function index(Request $request)
    {
        return view('admin.launch-checklist.index', [
            'sections' => $this->checklist->sections($request->user()),
            'progress' => $this->checklist->progress($request->user()),
        ]);
    }

    /** Tick off / untick a manual item. */
    public function toggle(Request $request, string $item)
    {
        abort_unless(in_array($item, $this->checklist->manualKeys($request->user()), true), 404);

        $this->checklist->toggle($item, $request->user());

        return redirect()->route('admin.launch-checklist.index')->withFragment($item);
    }
}
