<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\LegitimateEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AccountManagementController extends Controller
{
    // Same Philippine mobile format enforced at self-registration (0917 123 4567 / +63 917 123 4567)
    private const PH_MOBILE_REGEX = '/^(\+63|0)[\s\-]*9[\s\-]*\d{2}[\s\-]*\d{3}[\s\-]*\d{4}$/';
    private const PH_MOBILE_MESSAGE = 'Please enter a valid Philippine mobile number (e.g. 0917 123 4567 or +63 917 123 4567).';

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

        $counts = User::selectRaw('role, COUNT(*) as c')->groupBy('role')->pluck('c', 'role');

        return response()->json(array_merge($users->toArray(), [
            'stats' => [
                'total'        => $counts->sum(),
                'super_admins' => $counts['super_admin'] ?? 0,
                'admins'       => $counts['admin'] ?? 0,
                'foragers'     => $counts['forager'] ?? 0,
            ],
        ]));
    }

    /**
     * POST /api/admin/accounts
     * Create a new account (user or admin).
     */
    public function store(Request $request)
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email', ''))),
        ]);

        $data = $request->validate([
            'first_name'     => 'required|string|max:255',
            'last_name'      => 'required|string|max:255',
            'username'       => 'required|string|max:255|unique:users,username',
            'email'          => ['required', 'string', 'email:rfc,dns', 'max:255', new LegitimateEmail(), 'unique:users,email'],
            'password'       => 'required|string|min:8',
            'role'           => 'required|in:forager,admin,super_admin',
            'contact_number' => ['nullable', 'string', 'max:20', self::PH_MOBILE_REGEX],
            'address'        => 'nullable|string',
        ], [
            'email.email'          => 'Please enter a valid, deliverable email address.',
            'contact_number.regex' => self::PH_MOBILE_MESSAGE,
        ]);

        try {
            $user = DB::transaction(function () use ($data) {
                $user = User::create([
                    'name'           => $data['first_name'] . ' ' . $data['last_name'],
                    'first_name'     => $data['first_name'],
                    'last_name'      => $data['last_name'],
                    'username'       => $data['username'],
                    'email'          => $data['email'],
                    'password'       => $data['password'],
                    'role'           => $data['role'],
                    'contact_number' => $data['contact_number'] ?? '',
                    'address'        => $data['address'] ?? '',
                ]);

                $user->sendEmailVerificationNotification();

                return $user;
            });
        } catch (\Throwable $e) {
            Log::error('Admin account creation verification email failed', ['message' => $e->getMessage()]);
            return response()->json([
                'message' => 'Could not send verification email to that address. Please check the email and try again.',
            ], 500);
        }

        return response()->json([
            'message'               => 'Account created. A verification link has been sent to ' . $user->email . '.',
            'requires_verification' => true,
            'user'                  => $user,
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
        // Super admin roles can't be changed here, so ignore any role sent for them
        if ($user->isSuperAdmin()) {
            $request->request->remove('role');
        }

        // Prevent changing your own role
        if ($user->id === $request->user()->id && $request->has('role') && $request->role !== $user->role) {
            return response()->json(['message' => 'You cannot change your own role.'], 403);
        }

        if ($request->has('email')) {
            $request->merge([
                'email' => strtolower(trim((string) $request->input('email', ''))),
            ]);
        }

        $data = $request->validate([
            'first_name'     => 'sometimes|string|max:255',
            'last_name'      => 'sometimes|string|max:255',
            'username'       => "sometimes|string|max:255|unique:users,username,{$user->id}",
            'email'          => ['sometimes', 'string', 'email:rfc,dns', 'max:255', new LegitimateEmail(), "unique:users,email,{$user->id}"],
            'role'           => 'sometimes|in:forager,admin,super_admin',
            'contact_number' => ['sometimes', 'nullable', 'string', 'max:20', self::PH_MOBILE_REGEX],
            'address'        => 'sometimes|nullable|string',
        ], [
            'email.email'          => 'Please enter a valid, deliverable email address.',
            'contact_number.regex' => self::PH_MOBILE_MESSAGE,
        ]);

        if (isset($data['first_name']) || isset($data['last_name'])) {
            $data['name'] = ($data['first_name'] ?? $user->first_name) . ' ' . ($data['last_name'] ?? $user->last_name);
        }

        $emailChanged = isset($data['email']) && $data['email'] !== strtolower((string) $user->email);

        try {
            DB::transaction(function () use ($user, $data, $emailChanged) {
                $user->update($data);

                if ($emailChanged) {
                    $user->forceFill(['email_verified_at' => null])->save();
                    $user->tokens()->delete();
                    $user->sendEmailVerificationNotification();
                }
            });
        } catch (\Throwable $e) {
            Log::error('Admin account update verification email failed', ['message' => $e->getMessage()]);
            return response()->json([
                'message' => 'Could not send verification email to the updated address. Changes were not saved.',
            ], 500);
        }

        return response()->json([
            'message' => $emailChanged
                ? 'Account updated. A verification link has been sent to ' . $user->email . '.'
                : 'Account updated successfully.',
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
            'password' => $data['password'],
        ]);

        // Revoke all tokens so user must re-login
        $user->tokens()->delete();

        return response()->json(['message' => 'Password reset successfully.']);
    }

    /**
     * POST /api/admin/accounts/{user}/resend-verification
     * Resend email verification link for an unverified account.
     */
    public function resendVerification(User $user)
    {
        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'This account is already verified.',
            ]);
        }

        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            Log::error('Admin resend verification email failed', ['message' => $e->getMessage()]);
            return response()->json([
                'message' => 'Could not send verification email. Please try again.',
            ], 500);
        }

        return response()->json([
            'message' => 'Verification email sent to ' . $user->email . '.',
        ]);
    }
}
