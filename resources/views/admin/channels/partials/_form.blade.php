<div class="space-y-6">
    <div>
        <label for="name" class="block text-sm font-medium text-gray-400 mb-2">Channel Name</label>
        <input type="text" id="name" name="name" value="{{ old('name', $channel->name ?? '') }}" required
            class="w-full bg-gray-900 border border-gray-700 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 text-white">
    </div>

    <div>
        <label for="category_id" class="block text-sm font-medium text-gray-400 mb-2">Category</label>
        <select id="category_id" name="category_id" required
            class="w-full bg-gray-900 border border-gray-700 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 text-white">
            <option value="">Select a category</option>
            @foreach($categories as $category)
                <option value="{{ $category->id }}" {{ old('category_id', $channel->category_id ?? '') == $category->id ? 'selected' : '' }}>
                    {{ $category->name }}
                </option>
            @endforeach
        </select>
    </div>

    <div>
        <label for="stream_url" class="block text-sm font-medium text-gray-400 mb-2">Stream URL</label>
        <input type="url" id="stream_url" name="stream_url" value="{{ old('stream_url', $channel->stream_url ?? '') }}" required
            class="w-full bg-gray-900 border border-gray-700 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 text-white"
            placeholder="http://server.com:port/path/to/stream.m3u8">
    </div>

    <div>
        <label for="logo_url" class="block text-sm font-medium text-gray-400 mb-2">Logo URL (Optional)</label>
        <input type="url" id="logo_url" name="logo_url" value="{{ old('logo_url', $channel->logo_url ?? '') }}"
            class="w-full bg-gray-900 border border-gray-700 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 text-white">
    </div>

    <div class="flex items-center">
        <input type="hidden" name="is_active" value="0">
        <input id="is_active" name="is_active" type="checkbox" value="1" {{ old('is_active', $channel->is_active ?? true) ? 'checked' : '' }}
            class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-700 rounded bg-gray-900">
        <label for="is_active" class="ml-2 block text-sm text-gray-400">
            Channel is Active
        </label>
    </div>
</div>
