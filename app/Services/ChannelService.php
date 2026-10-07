<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Category;

class ChannelService
{
    public function getAllChannels()
    {
        return Channel::with('category')->paginate(20);
    }

    public function getAllCategories()
    {
        return Category::all();
    }

    public function createChannel(array $data)
    {
        return Channel::create($data);
    }

    public function updateChannel(Channel $channel, array $data)
    {
        return $channel->update($data);
    }

    public function deleteChannel(Channel $channel)
    {
        return $channel->delete();
    }

    public function createCategory(array $data)
    {
        return Category::create($data);
    }

    public function updateCategory(Category $category, array $data)
    {
        return $category->update($data);
    }

    public function deleteCategory(Category $category)
    {
        return $category->delete();
    }
}
