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
            'total_users'     => User::where('role', 'user')->count(),
            'total_admins'    => User::where('role', 'admin')->count(),
            'total_scans'     => Scan::count(),
            'scans_today'     => Scan::whereDate('created_at', today())->count(),
            'edible_scans'    => Scan::where('result_classification', 'edible')->count(),
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
            'role'  => 'sometimes|in:user,admin,super_admin',
        ]);

        $user->update($data);

        return response()->json($user);
    }

    // DELETE /api/admin/users/{user}
    public function destroyUser(User $user)
    {
        if ($user->isSuperAdmin()) {
            return response()->json(['message' => 'Cannot delete a Super Admin account.'], 403);
        }
        if ($user->isAdmin()) {
            return response()->json(['message' => 'Cannot delete an admin account. Use Account Management.'], 403);
        }
        $user->delete();

        return response()->json(['message' => 'User deleted.']);
    }

    // GET /api/admin/scans
    public function scans(Request $request)
    {
        $scans = Scan::with('user', 'species')
            ->when($request->user_id, fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->has('only_consented') && ($request->boolean('only_consented') || $request->only_consented == '1'), function ($q) {
                $q->whereHas('user', function ($u) {
                    $u->where('allow_data_training', 1)->orWhere('allow_data_training', true);
                });
            })
            ->latest()
            ->paginate(50);

        return response()->json($scans);
    }

    // GET /api/admin/scans/candidates — Privacy Filtered Scans for AI Model Training
    public function candidateScans(Request $request)
    {
        // STRICT PRIVACY FILTER: Only include scans from users with allow_data_training = true
        $candidates = Scan::with('user', 'species')
            ->whereHas('user', function ($q) {
                $q->where('allow_data_training', true);
            })
            ->latest()
            ->paginate(20);

        return response()->json($candidates);
    }
}
