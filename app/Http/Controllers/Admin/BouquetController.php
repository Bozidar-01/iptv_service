<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bouquet;
use App\Models\Channel;
use App\Services\BouquetService;
use Illuminate\Http\Request;

class BouquetController extends Controller
{
    protected $bouquetService;

    public function __construct(BouquetService $bouquetService)
    {
        $this->bouquetService = $bouquetService;
    }

    public function index()
    {
        $bouquets = $this->bouquetService->getAllBouquets();
        return view('admin.bouquets.index', compact('bouquets'));
    }

    public function create()
    {
        $channels = Channel::where('is_active', true)->get();
        return view('admin.bouquets.create', compact('channels'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:bouquets',
            'channels' => 'nullable|array',
            'channels.*' => 'exists:channels,id',
        ]);

        $this->bouquetService->createBouquet($data);

        return redirect()->route('admin.bouquets.index')->with('success', 'Bouquet created successfully.');
    }

    public function edit(Bouquet $bouquet)
    {
        $channels = Channel::where('is_active', true)->get();
        $selectedChannels = $bouquet->channels->pluck('id')->toArray();
        return view('admin.bouquets.edit', compact('bouquet', 'channels', 'selectedChannels'));
    }

    public function update(Request $request, Bouquet $bouquet)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:bouquets,name,' . $bouquet->id,
            'channels' => 'nullable|array',
            'channels.*' => 'exists:channels,id',
        ]);

        $this->bouquetService->updateBouquet($bouquet, $data);

        return redirect()->route('admin.bouquets.index')->with('success', 'Bouquet updated successfully.');
    }

    public function destroy(Bouquet $bouquet)
    {
        $this->bouquetService->deleteBouquet($bouquet);

        return redirect()->route('admin.bouquets.index')->with('success', 'Bouquet deleted successfully.');
    }
}
