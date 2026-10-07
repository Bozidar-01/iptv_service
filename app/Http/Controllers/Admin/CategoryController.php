<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\ChannelService;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    protected $channelService;

    public function __construct(ChannelService $channelService)
    {
        $this->channelService = $channelService;
    }

    public function index()
    {
        $categories = $this->channelService->getCategoriesWithCounts();
        return view('admin.categories.index', compact('categories'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:categories',
        ]);

        $this->channelService->createCategory($data);

        return redirect()->route('admin.categories.index')->with('success', __('Category created successfully.'));
    }

    public function update(Request $request, Category $category)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:categories,name,' . $category->id,
        ]);

        $this->channelService->updateCategory($category, $data);

        return redirect()->route('admin.categories.index')->with('success', __('Category updated successfully.'));
    }

    public function destroy(Category $category)
    {
        $this->channelService->deleteCategory($category);

        return redirect()->route('admin.categories.index')->with('success', __('Category deleted successfully.'));
    }
}
