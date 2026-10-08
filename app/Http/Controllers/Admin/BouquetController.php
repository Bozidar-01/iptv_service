<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bouquet;
use App\Models\Category;
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
        $categories = $this->categoriesWithCounts();

        return view('admin.bouquets.create', compact('categories'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:bouquets',
            'categories' => 'nullable|array',
            'categories.*' => 'exists:categories,id',
        ]);

        $this->bouquetService->createBouquet($data);

        return redirect()->route('admin.bouquets.index')->with('success', __('Bouquet created successfully.'));
    }

    public function edit(Bouquet $bouquet)
    {
        $categories = $this->categoriesWithCounts();
        $bouquet->loadCount(['channels', 'users']);

        return view('admin.bouquets.edit', compact('bouquet', 'categories'));
    }

    public function update(Request $request, Bouquet $bouquet)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:bouquets,name,'.$bouquet->id,
            'categories' => 'nullable|array',
            'categories.*' => 'exists:categories,id',
        ]);

        $this->bouquetService->updateBouquet($bouquet, $data);

        return redirect()->route('admin.bouquets.index')->with('success', __('Bouquet updated successfully.'));
    }

    public function destroy(Bouquet $bouquet)
    {
        $this->bouquetService->deleteBouquet($bouquet);

        return redirect()->route('admin.bouquets.index')->with('success', __('Bouquet deleted successfully.'));
    }

    private function categoriesWithCounts()
    {
        return Category::withCount('channels')->orderBy('name')->get()->where('channels_count', '>', 0)->values();
    }
}
