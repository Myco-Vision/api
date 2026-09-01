<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AccountManagementController extends Controller
{
    /**
     * GET /api/admin/accounts
     * List all accounts with optional filters (search, role).
     */
    public function index(Request $request)
    {
        $users = User::withCount('scans')
            ->when($request->search, fn ($q) =>
                $q->where(function ($q2) use ($request) {
                    $q2->where('name', 'like', "%{$request->search}%")
                       ->orWhere('email', 'like', "%{$request->search}%")
                       ->orWhere('username', 'like', "%{$request->search}%");
                })
            )
            ->when($request->role, fn ($q) =>
                $q->where('role', $request->role)
            )
            ->latest()
            ->paginate($request->per_page ?? 20);

        return response()->json($users);
    }

    /**
     * POST /api/admin/accounts
     * Create a new account (user or admin).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'first_name'     => 'required|string|max:255',
            'last_name'      => 'required|string|max:255',
            'username'       => 'required|string|max:255|unique:users,username',
            'email'          => 'required|email|unique:users,email',
            'password'       => 'required|string|min:8',
            'role'           => 'required|in:user,admin',
            'contact_number' => 'nullable|string|max:255',
            'address'        => 'nullable|string',
        ]);

        $user = User::create([
            'name'           => $data['first_name'] . ' ' . $data['last_name'],
            'first_name'     => $data['first_name'],
            'last_name'      => $data['last_name'],
            'username'       => $data['username'],
            'email'          => $data['email'],
            'password'       => Hash::make($data['password']),
            'role'           => $data['role'],
            'contact_number' => $data['contact_number'] ?? '',
            'address'        => $data['address'] ?? '',
        ]);

        return response()->json([
            'message' => 'Account created successfully.',
            'user'    => $user,
        ], 201);
    }

    /**
     * GET /api/admin/accounts/{user}
     * Show account details.
     */
    public function show(User $user)
    {
        return response()->json($user->loadCount('scans'));
    }

    /**
     * PUT /api/admin/accounts/{user}
     * Update an account.
     */
    public function update(Request $request, User $user)
    {
        // Prevent editing the super_admin's own role
        if ($user->id === $request->user()->id && $request->has('role') && $request->role !== 'super_admin') {
            return response()->json(['message' => 'You cannot change your own role.'], 403);
        }

        $data = $request->validate([
            'first_name'     => 'sometimes|string|max:255',
            'last_name'      => 'sometimes|string|max:255',
            'username'       => "sometimes|string|max:255|unique:users,username,{$user->id}",
            'email'          => "sometimes|email|unique:users,email,{$user->id}",
            'role'           => 'sometimes|in:user,admin',
            'contact_number' => 'sometimes|nullable|string|max:255',
            'address'        => 'sometimes|nullable|string',
        ]);

        // Auto-update name if first/last name provided
        if (isset($data['first_name']) || isset($data['last_name'])) {
            $data['name'] = ($data['first_name'] ?? $user->first_name) . ' ' . ($data['last_name'] ?? $user->last_name);
        }

        $user->update($data);

        return response()->json([
            'message' => 'Account updated successfully.',
            'user'    => $user->fresh(),
        ]);
    }

    /**
     * DELETE /api/admin/accounts/{user}
     * Delete an account.
     */
    public function destroy(Request $request, User $user)
    {
        // Prevent self-deletion
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 403);
        }

        // Prevent deletion of other super_admin accounts
        if ($user->isSuperAdmin()) {
            return response()->json(['message' => 'Cannot delete a Super Admin account.'], 403);
        }

        $user->delete();

        return response()->json(['message' => 'Account deleted successfully.']);
    }

    /**
     * POST /api/admin/accounts/{user}/reset-password
     * Reset a user's password.
     */
    public function resetPassword(Request $request, User $user)
    {
        $data = $request->validate([
            'password' => 'required|string|min:8',
        ]);

        $user->update([
            'password' => Hash::make($data['password']),
        ]);

        // Revoke all tokens so user must re-login
        $user->tokens()->delete();

        return response()->json(['message' => 'Password reset successfully.']);
    }
}
