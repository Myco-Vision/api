<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleAuthController extends Controller
{
    /**
     * POST /api/auth/google
     *
     * Receives an OAuth2 access_token from the frontend (google.accounts.oauth2),
     * fetches user info from Google, then finds or creates the user.
     */
    public function handleToken(Request $request)
    {
        $request->validate([
            'access_token' => 'required|string',
        ]);

        // Fetch user info from Google using the access token
        $response = Http::withToken($request->access_token)
            ->get('https://www.googleapis.com/oauth2/v2/userinfo');

        if ($response->failed()) {
            return response()->json(['message' => 'Invalid or expired Google token.'], 401);
        }

        $payload = $response->json();

        $googleId = $payload['id'];
        $email    = $payload['email'] ?? null;
        $name     = $payload['name'] ?? '';
        $picture  = $payload['picture'] ?? null;

        if (!$email) {
            return response()->json(['message' => 'Google account has no email address.'], 422);
        }

        // Split name into first/last
        $nameParts = explode(' ', $name, 2);
        $firstName = $nameParts[0] ?? $name;
        $lastName  = $nameParts[1] ?? '';

        // Find existing user by google_id OR email
        $user = User::where('google_id', $googleId)
                    ->orWhere('email', $email)
                    ->first();

        if ($user) {
            // Link Google ID if not already linked
            if (!$user->google_id) {
                $user->update(['google_id' => $googleId]);
            }
            // Update profile photo if not set
            if (!$user->profile_photo && $picture) {
                $user->update(['profile_photo' => $picture]);
            }

            // Detect if profile is still missing required fields
            $needsCompletion = $user->needs_profile_completion
                || !$user->contact_number
                || !$user->address
                || str_starts_with($user->username ?? '', 'user_');

            if ($needsCompletion && !$user->needs_profile_completion) {
                $user->update(['needs_profile_completion' => true]);
            }
        } else {
            // Register new user via Google — requires profile completion
            $user = User::create([
                'name'                      => $name,
                'first_name'                => $firstName,
                'last_name'                 => $lastName,
                'username'                  => 'user_' . Str::random(8),
                'email'                     => $email,
                'google_id'                 => $googleId,
                'profile_photo'             => $picture,
                'password'                  => bcrypt(Str::random(32)),
                'role'                      => 'forager',
                'contact_number'            => null,
                'address'                   => null,
                'needs_profile_completion'  => true,
            ]);

            $needsCompletion = true;
        }

        // Revoke old tokens and issue a fresh one
        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message'          => 'Google login successful.',
            'user'             => $user->fresh(),
            'token'            => $token,
            'needs_completion' => (bool) ($needsCompletion ?? false),
        ]);
    }
}
