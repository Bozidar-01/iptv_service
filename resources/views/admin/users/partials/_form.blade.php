<div class="grid grid-cols-1 md:grid-cols-2 gap-8">
    <!-- Basic Info -->
    <div class="space-y-6">
        <h3 class="text-lg font-semibold text-white border-b border-gray-700 pb-2">Account Information</h3>
        
        <div>
            <label for="username" class="block text-sm font-medium text-gray-400 mb-2">Username</label>
            <input type="text" id="username" name="username" value="{{ old('username', $user->username ?? '') }}" required
                class="w-full bg-gray-900 border border-gray-700 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 text-white">
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-gray-400 mb-2">
                Password {{ isset($user) ? '(Leave blank to keep current)' : '' }}
            </label>
            <input type="password" id="password" name="password" {{ isset($user) ? '' : 'required' }}
                class="w-full bg-gray-900 border border-gray-700 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 text-white">
        </div>

        <div>
            <label for="role" class="block text-sm font-medium text-gray-400 mb-2">System Role</label>
            <select id="role" name="role" required
                class="w-full bg-gray-900 border border-gray-700 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 text-white">
                <option value="user" {{ old('role', $user->role ?? 'user') == 'user' ? 'selected' : '' }}>Standard User</option>
                <option value="admin" {{ old('role', $user->role ?? '') == 'admin' ? 'selected' : '' }}>Administrator</option>
            </select>
        </div>

        <div>
            <label for="status" class="block text-sm font-medium text-gray-400 mb-2">Status</label>
            <select id="status" name="status" required
                class="w-full bg-gray-900 border border-gray-700 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 text-white">
                <option value="active" {{ old('status', $user->status ?? 'active') == 'active' ? 'selected' : '' }}>Active</option>
                <option value="disabled" {{ old('status', $user->status ?? '') == 'disabled' ? 'selected' : '' }}>Disabled</option>
            </select>
        </div>
    </div>

    <!-- IPTV Specifics -->
    <div class="space-y-6">
        <h3 class="text-lg font-semibold text-white border-b border-gray-700 pb-2">IPTV Settings</h3>

        <div>
            <label for="expiration_date" class="block text-sm font-medium text-gray-400 mb-2">Expiration Date</label>
            <input type="date" id="expiration_date" name="expiration_date" 
                value="{{ old('expiration_date', isset($user->expiration_date) ? $user->expiration_date->format('Y-m-d') : '') }}"
                class="w-full bg-gray-900 border border-gray-700 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 text-white">
            <p class="text-xs text-gray-500 mt-1 italic">Leave empty for lifetime access</p>
        </div>

        <div>
            <label for="max_connections" class="block text-sm font-medium text-gray-400 mb-2">Max Multi-Connections</label>
            <input type="number" id="max_connections" name="max_connections" 
                value="{{ old('max_connections', $user->max_connections ?? 1) }}" min="1" required
                class="w-full bg-gray-900 border border-gray-700 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 text-white">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-400 mb-2">Assigned Bouquets</label>
            <div class="bg-gray-900 border border-gray-700 rounded-xl max-h-48 overflow-y-auto p-4 space-y-2">
                @foreach($bouquets as $bouquet)
                    <label class="flex items-center space-x-3 cursor-pointer group">
                        <input type="checkbox" name="bouquets[]" value="{{ $bouquet->id }}" 
                            {{ in_array($bouquet->id, $selectedBouquets ?? []) ? 'checked' : '' }}
                            class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-700 rounded bg-gray-900">
                        <span class="text-sm text-gray-300 group-hover:text-white transition-colors">{{ $bouquet->name }}</span>
                    </label>
                @endforeach
            </div>
        </div>
    </div>
</div>
