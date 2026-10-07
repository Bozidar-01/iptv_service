<div class="space-y-6" x-data="{ selectedChannels: {{ json_encode($selectedChannels ?? []) }} }">
    <div>
        <label for="name" class="block text-sm font-medium text-gray-400 mb-2">Bouquet Name</label>
        <input type="text" id="name" name="name" value="{{ old('name', $bouquet->name ?? '') }}" required
            class="w-full bg-gray-900 border border-gray-700 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 text-white">
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-400 mb-2">Assign Channels</label>
        <div class="bg-gray-900 border border-gray-700 rounded-xl overflow-hidden">
            <div class="p-4 border-b border-gray-700 bg-gray-800/50 flex items-center justify-between">
                <span class="text-xs text-gray-500 uppercase font-bold tracking-wider">Available Channels</span>
                <span class="text-xs text-blue-400 font-medium" x-text="selectedChannels.length + ' selected'"></span>
            </div>
            <div class="max-h-96 overflow-y-auto p-4 grid grid-cols-1 md:grid-cols-2 gap-3">
                @foreach($channels as $channel)
                    <label class="flex items-center space-x-3 p-3 rounded-lg border border-gray-800 hover:bg-gray-800/50 transition-colors cursor-pointer group">
                        <input type="checkbox" name="channels[]" value="{{ $channel->id }}" 
                            x-model="selectedChannels"
                            class="h-5 w-5 text-blue-600 focus:ring-blue-500 border-gray-700 rounded bg-gray-900">
                        <div class="flex items-center space-x-3">
                            @if($channel->logo_url)
                                <img src="{{ $channel->logo_url }}" class="h-6 w-8 object-contain bg-black rounded p-0.5">
                            @endif
                            <div class="flex flex-col">
                                <span class="text-sm font-medium text-white group-hover:text-blue-400 transition-colors">{{ $channel->name }}</span>
                                <span class="text-[10px] text-gray-500 uppercase">{{ $channel->category->name ?? 'General' }}</span>
                            </div>
                        </div>
                    </label>
                @endforeach
            </div>
        </div>
    </div>
</div>
