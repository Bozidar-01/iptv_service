<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Bouquet;
use App\Services\UserService;
use Illuminate\Http\Request;

class UserController extends Controller
{
    protected $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function index()
    {
        $users = $this->userService->getAllUsers();
        return view('admin.users.index', compact('users'));
    }

    public function create()
    {
        $bouquets = Bouquet::all();
        return view('admin.users.create', compact('bouquets'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string|max:255|unique:users',
            'password' => 'required|string|min:6',
            'status' => 'required|in:active,disabled',
            'role' => 'required|in:admin,user',
            'expiration_date' => 'nullable|date',
            'max_connections' => 'required|integer|min:1',
            'bouquets' => 'nullable|array',
            'bouquets.*' => 'exists:bouquets,id',
        ]);

        $this->userService->createUser($data);

        return redirect()->route('admin.users.index')->with('success', 'User created successfully.');
    }

    public function edit(User $user)
    {
        $bouquets = Bouquet::all();
        $selectedBouquets = $user->bouquets->pluck('id')->toArray();
        return view('admin.users.edit', compact('user', 'bouquets', 'selectedBouquets'));
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'username' => 'required|string|max:255|unique:users,username,' . $user->id,
            'password' => 'nullable|string|min:6',
            'status' => 'required|in:active,disabled',
            'role' => 'required|in:admin,user',
            'expiration_date' => 'nullable|date',
            'max_connections' => 'required|integer|min:1',
            'bouquets' => 'nullable|array',
            'bouquets.*' => 'exists:bouquets,id',
        ]);

        $this->userService->updateUser($user, $data);

        return redirect()->route('admin.users.index')->with('success', 'User updated successfully.');
    }

    public function destroy(User $user)
    {
        $this->userService->deleteUser($user);

        return redirect()->route('admin.users.index')->with('success', 'User deleted successfully.');
    }

    public function toggleStatus(User $user)
    {
        $this->userService->toggleStatus($user);
        return back()->with('success', 'User status updated.');
    }
}
