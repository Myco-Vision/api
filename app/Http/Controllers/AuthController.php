<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Rules\LegitimateEmail;
use App\Rules\UniqueEmail;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // POST /api/register
    public function register(Request $request)
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email', ''))),
        ]);

        $data = $request->validate([
            'first_name'     => 'required|string|max:255',
            'last_name'      => 'required|string|max:255',
            'username'       => 'required|string|max:255|unique:users,username',
            'email'          => ['required', 'string', 'email:rfc,dns', 'max:255', new LegitimateEmail(), new UniqueEmail(), 'unique:users,email'],
            'contact_number' => ['required', 'string', 'max:20', 'regex:/^(\+63|0)[\s\-]*9[\s\-]*\d{2}[\s\-]*\d{3}[\s\-]*\d{4}$/'],
            'address'        => 'required|string',
            'password'       => 'required|string|min:8|confirmed',
        ], [
            'email.email'          => 'Please enter a valid, deliverable email address.',
            'email.unique'         => 'This email address is already registered. Please use a different email.',
            'contact_number.regex' => 'Please enter a valid Philippine mobile number (e.g. 0917 123 4567 or +63 917 123 4567).',
        ]);

        try {
            $user = DB::transaction(function () use ($data) {
                $user = User::create([
                    'name'           => $data['first_name'] . ' ' . $data['last_name'],
                    'first_name'     => $data['first_name'],
                    'last_name'      => $data['last_name'],
                    'username'       => $data['username'],
                    'email'          => $data['email'],
                    'contact_number' => $data['contact_number'],
                    'address'        => $data['address'],
                    'password'       => $data['password'],
                    'role'           => 'forager',
                ]);

                $user->sendEmailVerificationNotification();

                return $user;
            });
        } catch (\Throwable $e) {
            Log::error('Registration verification email failed', ['message' => $e->getMessage()]);
            return response()->json([
                'message' => 'Could not send verification email to that address. Please check your email and try again.',
            ], 500);
        }

        return response()->json([
            'message'               => 'Registration successful. Please verify your email address using the link sent to your inbox before signing in.',
            'requires_verification' => true,
            'email'                 => $user->email,
        ], 201);
    }

    // GET /api/register/check-email
    public function checkEmail(Request $request)
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email', ''))),
        ]);

        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc,dns', 'max:255', new LegitimateEmail()],
        ], [
            'email.email' => 'Please enter a valid, deliverable email address.',
        ]);

        $taken = UniqueEmail::existsInUsers($data['email']);

        return response()->json([
            'available' => ! $taken,
            'message'   => $taken ? 'This email address is already registered.' : 'Email is available.',
        ]);
    }

    // GET /api/email/verify/{id}/{hash}
    public function verifyEmail(Request $request, int $id, string $hash)
    {
        if (! URL::hasValidRelativeSignature($request)) {
            return response()->json([
                'message' => 'This verification link is invalid or has expired. Please request a new verification email.',
            ], 403);
        }

        $user = User::find($id);
        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), (string) $hash)) {
            return response()->json([
                'message' => 'Invalid email verification link.',
            ], 403);
        }

        $alreadyVerified = $user->hasVerifiedEmail();
        if (! $alreadyVerified) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message'          => $alreadyVerified
                ? 'Your email address is already verified.'
                : 'Email verified successfully! Your account is now active.',
            'already_verified' => $alreadyVerified,
            'user'             => $user->fresh(),
            'token'            => $token,
        ]);
    }

    // POST /api/email/verification-notification
    public function resendVerification(Request $request)
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email', ''))),
        ]);

        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc,dns', new LegitimateEmail()],
        ], [
            'email.email' => 'Please enter a valid, deliverable email address.',
        ]);

        $user = User::whereRaw('LOWER(email) = ?', [$data['email']])->first();

        if ($user && ! $user->hasVerifiedEmail()) {
            try {
                $user->sendEmailVerificationNotification();
            } catch (\Throwable $e) {
                Log::error('Resend verification email failed', ['message' => $e->getMessage()]);
                return response()->json([
                    'message' => 'Failed to send verification email. Please try again shortly.',
                ], 500);
            }
        }

        return response()->json([
            'message' => 'If an unverified account exists for that email, a new verification link has been sent.',
        ]);
    }

    // POST /api/forgot-password
    public function sendResetLink(Request $request)
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email', ''))),
        ]);

        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc,dns', new LegitimateEmail()],
        ], [
            'email.email' => 'Please enter a valid, deliverable email address.',
        ]);

        $user = User::whereRaw('LOWER(email) = ?', [$data['email']])->first();

        if ($user && $this->requiresEmailVerification($user)) {
            return response()->json([
                'message'               => 'Your email address is not verified yet. Please verify your email first before resetting your password.',
                'error'                 => 'EMAIL_NOT_VERIFIED',
                'requires_verification' => true,
                'email'                 => $user->email,
            ], 403);
        }

        if ($user) {
            try {
                $status = Password::sendResetLink(['email' => $user->email]);
                if ($status === Password::RESET_THROTTLED) {
                    return response()->json([
                        'message' => 'A reset link was just sent. Please wait a minute before trying again.',
                    ], 429);
                }
            } catch (\Throwable $e) {
                Log::error('Password reset email failed', [
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                ]);
                return response()->json([
                    'message' => 'Could not send password reset email. Please check the mail service configuration and try again shortly.',
                ], 500);
            }
        }

        // Generic message — never reveal whether the email actually exists.
        return response()->json([
            'message' => 'If that email is registered, a reset link has been sent.',
        ]);
    }

    // POST /api/reset-password
    public function resetPassword(Request $request)
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email', ''))),
        ]);

        $data = $request->validate([
            'token'    => 'required|string',
            'email'    => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $matchedUser = User::whereRaw('LOWER(email) = ?', [$data['email']])->first();
        $credentials = [
            'email'                 => $matchedUser?->email ?? $data['email'],
            'password'              => $data['password'],
            'password_confirmation' => $request->input('password_confirmation'),
            'token'                 => $data['token'],
        ];

        $status = Password::reset(
            $credentials,
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
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email', ''))),
        ]);

        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::whereRaw('LOWER(email) = ?', [$data['email']])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($this->requiresEmailVerification($user)) {
            return response()->json([
                'message'               => 'Please verify your email address before signing in. Check your inbox for the verification link.',
                'error'                 => 'EMAIL_NOT_VERIFIED',
                'requires_verification' => true,
                'email'                 => $user->email,
            ], 403);
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

    // PUT /api/me
    public function updateMe(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'first_name' => 'sometimes|string|max:255',
            'last_name'  => 'sometimes|string|max:255',
            'username'   => 'sometimes|string|max:255|unique:users,username,' . $user->id,
        ]);

        if (isset($data['first_name']) || isset($data['last_name'])) {
            $data['name'] = trim(($data['first_name'] ?? $user->first_name) . ' ' . ($data['last_name'] ?? $user->last_name));
        }

        $user->update($data);

        return response()->json($user->fresh());
    }

    // PUT /api/user/consent
    public function updateConsent(Request $request)
    {
        $data = $request->validate([
            'allow_data_training' => 'required|boolean',
        ]);

        $user = $request->user();
        $user->update([
            'allow_data_training'        => $data['allow_data_training'],
            'data_training_consented_at' => $data['allow_data_training'] ? now() : null,
        ]);

        return response()->json([
            'message' => 'Consent preference updated successfully.',
            'user'    => $user,
        ]);
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

    // POST /api/change-password (auth:sanctum)
    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->update(['password' => $data['password']]);

        return response()->json(['message' => 'Password updated successfully.']);
    }

    // POST /api/user/profile (auth:sanctum)
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'first_name'     => 'required|string|max:255',
            'last_name'      => 'required|string|max:255',
            'username'       => 'required|string|max:255|unique:users,username,' . $user->id,
            'contact_number' => ['required', 'string', 'max:20', 'regex:/^(\+63|0)[\s\-]*9[\s\-]*\d{2}[\s\-]*\d{3}[\s\-]*\d{4}$/'],
            'address'        => ['required', 'string', 'max:500', 'not_regex:/@/'],
        ], [
            'address.not_regex'    => 'Please enter a street address, not an email.',
            'contact_number.regex' => 'Please enter a valid Philippine mobile number (e.g. 0917 123 4567 or +63 917 123 4567).',
        ]);

        $user->update([
            'name'           => trim($data['first_name']) . ' ' . trim($data['last_name']),
            'first_name'     => trim($data['first_name']),
            'last_name'      => trim($data['last_name']),
            'username'       => $data['username'],
            'contact_number' => $data['contact_number'],
            'address'        => $data['address'],
        ]);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user'    => $user->fresh(),
        ]);
    }

    // POST /api/user/avatar (auth:sanctum)
    public function updateAvatar(Request $request)
    {
        $request->validate([
            'avatar' => 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $user = $request->user();
        $file = $request->file('avatar');
        $realPath = $file->getRealPath();
        $raw = $realPath !== false ? @file_get_contents($realPath) : false;
        if (! is_string($raw) || $raw === '') {
            throw ValidationException::withMessages(['avatar' => ['The uploaded image could not be read.']]);
        }

        // Re-encode and crop server-side when GD is available; otherwise verify and store uploaded bytes directly
        if (function_exists('imagecreatefromstring')) {
            $src = @imagecreatefromstring($raw);
            if ($src === false) {
                throw ValidationException::withMessages(['avatar' => ['The image could not be processed.']]);
            }

            // Center-crop to a square, then resize to 256x256 (re-encoding also strips metadata)
            $size = 256;
            $w = imagesx($src);
            $h = imagesy($src);
            $side = min($w, $h);
            $dst = imagecreatetruecolor($size, $size);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // flatten transparency
            imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), $size, $size, $side, $side);

            ob_start();
            imagejpeg($dst, null, 85);
            $contents = (string) ob_get_clean();
            imagedestroy($src);
            imagedestroy($dst);
            $ext = 'jpg';
        } else {
            $info = @getimagesizefromstring($raw);
            $extMap = [
                IMAGETYPE_JPEG => 'jpg',
                IMAGETYPE_PNG  => 'png',
                IMAGETYPE_WEBP => 'webp',
            ];
            if ($info === false || ! isset($extMap[$info[2]])) {
                throw ValidationException::withMessages(['avatar' => ['The image could not be processed.']]);
            }
            $contents = $raw;
            $ext = $extMap[$info[2]];
        }

        $path = 'avatars/' . $user->id . '_' . Str::random(16) . '.' . $ext;
        $disk = Storage::disk('public');

        try {
            $stored = $disk->put($path, $contents);
        } catch (\Throwable $e) {
            Log::error('Avatar upload failed', [
                'user_id' => $user->id,
                'path'    => $path,
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to save profile photo to storage.',
            ], 500);
        }

        if (! $stored) {
            Log::error('Avatar upload returned false', [
                'user_id' => $user->id,
                'path'    => $path,
            ]);

            return response()->json([
                'message' => 'Failed to save profile photo to storage.',
            ], 500);
        }

        $old = $user->profile_photo;
        $user->update(['profile_photo' => $path]);

        // Best-effort cleanup of the previous avatar if it was stored on our disk (not a Google URL)
        if ($old && ! preg_match('#^https?://#i', $old)) {
            try {
                $disk->delete($old);
            } catch (\Throwable $e) {
                Log::warning('Old avatar cleanup failed', [
                    'user_id' => $user->id,
                    'old'     => $old,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => 'Profile photo updated successfully.',
            'user'    => $user->fresh(),
        ]);
    }

    // DELETE /api/user (auth:sanctum) — self-service account deletion
    public function deleteAccount(Request $request)
    {
        $data = $request->validate([
            'password' => 'required|string',
        ]);

        $user = $request->user();

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The password is incorrect.'],
            ]);
        }

        if ($user->isAdmin()) {
            return response()->json([
                'message' => 'Admin accounts cannot be deleted here. Contact a Super Admin.',
            ], 403);
        }

        // Collect stored files before the rows disappear
        $imagePaths = $user->scans()->pluck('image_path')->filter()->all();
        $photo = $user->profile_photo;

        DB::transaction(function () use ($user) {
            // Billing records are retained for accounting/disputes: stamp the email,
            // end any active plan, and let the FK null out user_id on delete.
            $email = $user->email;
            $user->subscriptions()->get()->each(function ($sub) use ($user, $email) {
                $changes = ['deleted_user_email' => $email];
                if ($sub->status === 'active') {
                    $changes += [
                        'status'       => 'cancelled',
                        'auto_renew'   => false,
                        'cancelled_at' => now(),
                    ];
                    SubscriptionEvent::log($user->id, 'cancelled', $sub->id, null, [
                        'reason' => 'account_deleted_by_user',
                    ]);
                }
                $sub->update($changes);
            });
            $user->refundRequests()->update(['deleted_user_email' => $email]);
            SubscriptionEvent::where('user_id', $user->id)->update(['deleted_user_email' => $email]);

            $user->tokens()->delete();
            $user->scans()->delete();
            $user->delete();
        });

        // Remove files only after the DB commit succeeded
        $disk = Storage::disk('public');
        foreach ($imagePaths as $path) {
            $disk->delete($path);
        }
        if ($photo && ! preg_match('#^https?://#i', $photo)) {
            $disk->delete($photo);
        }

        return response()->json(['message' => 'Your account has been deleted.']);
    }

    private function requiresEmailVerification(User $user): bool
    {
        if ($user->hasVerifiedEmail()) {
            return false;
        }

        // Exempt seeded bootstrap admin accounts so they cannot be locked out.
        if (
            $user->role !== 'forager'
            && in_array(strtolower((string) $user->email), ['superadmin@mycovision.com', 'admin@mycovision.com', 'admin@mycovision.app'], true)
        ) {
            return false;
        }

        return true;
    }
}
