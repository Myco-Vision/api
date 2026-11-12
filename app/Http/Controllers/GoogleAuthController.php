<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleAuthController extends Controller
{
    private function finalizeGoogleLogin(User $user, bool $needsCompletion, ?string $googleId = null, ?string $picture = null)
    {
        if ($googleId && ! $user->google_id) {
            $user->google_id = $googleId;
        }
        if ($picture && ! $user->profile_photo) {
            $user->profile_photo = $picture;
        }
        if ($user->isDirty()) {
            $user->save();
        }

        // Google already verified ownership of this email address
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message'          => 'Google login successful.',
            'user'             => $user->fresh(),
            'token'            => $token,
            'needs_completion' => $needsCompletion,
        ]);
    }

    /**
     * POST /api/auth/google
     *
     * Receives an OAuth2 access_token from the frontend (google.accounts.oauth2),
     * fetches user info from Google, then:
     * - For new / incomplete profiles: creates/issues session so they can set their password in /complete-profile.
     * - For existing registered accounts: returns a short-lived challenge_token requiring their registered password.
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
        $email    = isset($payload['email']) ? strtolower(trim($payload['email'])) : null;
        $name     = $payload['name'] ?? '';
        $picture  = $payload['picture'] ?? null;

        if (! $email) {
            return response()->json(['message' => 'Google account has no email address.'], 422);
        }

        // Split name into first/last
        $nameParts = explode(' ', $name, 2);
        $firstName = $nameParts[0] ?? $name;
        $lastName  = $nameParts[1] ?? '';

        // Find existing user by google_id OR email (case-insensitive)
        $user = User::where('google_id', $googleId)
            ->orWhereRaw('LOWER(email) = ?', [$email])
            ->first();

        if ($user) {
            $needsCompletion = $user->needs_profile_completion
                || ! $user->contact_number
                || ! $user->address
                || str_starts_with($user->username ?? '', 'user_');

            if ($needsCompletion && ! $user->needs_profile_completion) {
                $user->update(['needs_profile_completion' => true]);
            }

            // Registered users who have already set a password must confirm it after Google sign-in
            if (! $needsCompletion) {
                $challengeToken = Crypt::encryptString(json_encode([
                    'user_id'   => $user->id,
                    'google_id' => $googleId,
                    'picture'   => $picture,
                    'exp'       => now()->addMinutes(10)->timestamp,
                ]));

                return response()->json([
                    'requires_password' => true,
                    'challenge_token'   => $challengeToken,
                    'email'             => $user->email,
                    'name'              => $user->name ?: $name,
                    'avatar'            => $picture,
                    'message'           => 'Google account verified. Please enter your account password to finish signing in.',
                ]);
            }

            return $this->finalizeGoogleLogin($user, true, $googleId, $picture);
        }

        // Brand-new user via Google — requires profile completion (where they create their password)
        $user = User::create([
            'name'                     => $name,
            'first_name'               => $firstName,
            'last_name'                => $lastName,
            'username'                 => 'user_' . Str::random(8),
            'email'                    => $email,
            'google_id'                => $googleId,
            'profile_photo'            => $picture,
            'password'                 => bcrypt(Str::random(32)),
            'role'                     => 'forager',
            'contact_number'           => null,
            'address'                  => null,
            'needs_profile_completion' => true,
        ]);

        return $this->finalizeGoogleLogin($user, true);
    }

    /**
     * POST /api/auth/google/verify-password
     *
     * Verifies the account password after Google OAuth verified identity,
     * then issues the Sanctum auth token.
     */
    public function verifyPassword(Request $request)
    {
        $data = $request->validate([
            'challenge_token' => 'required|string',
            'password'        => 'required|string',
        ]);

        try {
            $challenge = json_decode(Crypt::decryptString($data['challenge_token']), true);
        } catch (DecryptException) {
            return response()->json(['message' => 'Google verification session is invalid. Please sign in with Google again.'], 401);
        }

        if (! is_array($challenge) || empty($challenge['user_id']) || empty($challenge['exp']) || now()->timestamp > (int) $challenge['exp']) {
            return response()->json(['message' => 'Google verification session has expired. Please sign in with Google again.'], 401);
        }

        $user = User::find($challenge['user_id']);
        if (! $user) {
            return response()->json(['message' => 'Account not found. Please try again.'], 404);
        }

        if (! Hash::check($data['password'], $user->password)) {
            return response()->json([
                'message' => 'Incorrect password. Please enter the password you created during registration.',
            ], 422);
        }

        return $this->finalizeGoogleLogin(
            $user,
            false,
            $challenge['google_id'] ?? null,
            $challenge['picture'] ?? null
        );
    }
}
