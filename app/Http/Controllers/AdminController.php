<?php

namespace App\Http\Controllers;

use App\Models\Scan;
use App\Models\User;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    // GET /api/admin/dashboard  — key stats
    public function dashboard()
    {
        return response()->json([
            'total_users'  => User::where('role', 'user')->count(),
            'total_scans'  => Scan::count(),
            'scans_today'  => Scan::whereDate('created_at', today())->count(),
            'edible_scans' => Scan::where('result_classification', 'edible')->count(),
            'poisonous_scans' => Scan::where('result_classification', 'poisonous')->count(),
        ]);
    }

    // GET /api/admin/users
    public function users(Request $request)
    {
        $users = User::withCount('scans')
            ->when($request->search, fn ($q) =>
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%")
            )
            ->latest()
            ->paginate(20);

        return response()->json($users);
    }

    // GET /api/admin/users/{user}
    public function showUser(User $user)
    {
        return response()->json($user->loadCount('scans')->load('scans'));
    }

    // PUT /api/admin/users/{user}
    public function updateUser(Request $request, User $user)
    {
        $data = $request->validate([
            'name'  => 'sometimes|string|max:255',
            'email' => "sometimes|email|unique:users,email,{$user->id}",
            'role'  => 'sometimes|in:user,admin',
        ]);

        $user->update($data);

        return response()->json($user);
    }

    // DELETE /api/admin/users/{user}
    public function destroyUser(User $user)
    {
        if ($user->isAdmin()) {
            return response()->json(['message' => 'Cannot delete an admin account.'], 403);
        }
        $user->delete();

        return response()->json(['message' => 'User deleted.']);
    }

    // GET /api/admin/scans
    public function scans(Request $request)
    {
        $scans = Scan::with('user', 'species')
            ->when($request->user_id, fn ($q) => $q->where('user_id', $request->user_id))
            ->latest()
            ->paginate(20);

        return response()->json($scans);
    }
}
