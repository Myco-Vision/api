<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // POST /api/register
    public function register(Request $request)
    {
        $data = $request->validate([
            'first_name'     => 'required|string|max:255',
            'last_name'      => 'required|string|max:255',
            'username'       => 'required|string|max:255|unique:users,username',
            'email'          => 'required|email|unique:users,email',
            'contact_number' => ['required', 'string', 'max:20', 'regex:/^(\+63|0)[\s\-]*9[\s\-]*\d{2}[\s\-]*\d{3}[\s\-]*\d{4}$/'],
            'address'        => 'required|string',
            'password'       => 'required|string|min:8|confirmed',
        ], [
            'contact_number.regex' => 'Please enter a valid Philippine mobile number (e.g. 0917 123 4567 or +63 917 123 4567).',
        ]);

        $user = User::create([
            'name'           => $data['first_name'] . ' ' . $data['last_name'],
            'first_name'     => $data['first_name'],
            'last_name'      => $data['last_name'],
            'username'       => $data['username'],
            'email'          => $data['email'],
            'contact_number' => $data['contact_number'],
            'address'        => $data['address'],
            'password'       => $data['password'],
            'role'           => 'user',
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful.',
            'user'    => $user,
            'token'   => $token,
        ], 201);
    }

    // GET /api/register/check-email
    public function checkEmail(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
        ]);

        $taken = User::where('email', $data['email'])->exists();

        return response()->json([
            'available' => ! $taken,
            'message'    => $taken ? 'This email is already registered.' : 'Email is available.',
        ]);
    }

    // POST /api/forgot-password
    public function sendResetLink(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        Password::sendResetLink($request->only('email'));

        // Generic message — never reveal whether the email actually exists.
        return response()->json([
            'message' => 'If that email is registered, a reset link has been sent.',
        ]);
    }

    // POST /api/reset-password
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token'    => 'required|string',
            'email'    => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => $password,
                ])->setRememberToken(Str::random(60));

                $user->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['message' => __($status)]);
        }

        throw ValidationException::withMessages([
            'password' => [__($status)],
        ]);
    }

    // POST /api/login
    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Revoke previous tokens (single session)
        $user->tokens()->delete();

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'user'    => $user,
            'token'   => $token,
        ]);
    }

    // POST /api/logout
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    // GET /api/me
    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    // POST /api/complete-profile  (auth:sanctum)
    public function completeProfile(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'username'       => 'required|string|max:255|unique:users,username,' . $user->id,
            'contact_number' => ['required', 'string', 'max:20', 'regex:/^[\+]?[0-9\s\-]{7,20}$/'],
            'address'        => 'required|string|max:500',
            'password'       => 'required|string|min:8|confirmed',
        ]);

        $user->update([
            'username'                  => $data['username'],
            'contact_number'            => $data['contact_number'],
            'address'                   => $data['address'],
            'password'                  => $data['password'],
            'needs_profile_completion'  => false,
        ]);

        return response()->json([
            'message' => 'Profile completed successfully.',
            'user'    => $user->fresh(),
        ]);
    }
}
