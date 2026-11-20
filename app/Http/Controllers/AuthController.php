<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Rules\LegitimateEmail;
use App\Rules\UniqueEmail;
use App\Support\MailDiagnostics;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
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
                $hint = MailDiagnostics::diagnose('Password reset email failed', $e);

                return response()->json([
                    'message' => 'Could not send password reset email. ' . $hint,
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

    // POST /api/login (Regular User / Forager login)
    public function login(Request $request)
    {
        $login = strtolower(trim((string) ($request->input('login') ?? $request->input('email', ''))));

        $data = $request->validate([
            'email'    => 'sometimes|string',
            'login'    => 'sometimes|string',
            'password' => 'required|string',
        ]);

        if (empty($login)) {
            throw ValidationException::withMessages([
                'email' => ['Please enter your email address or username.'],
            ]);
        }

        // Support login using either email or username
        $user = User::whereRaw('LOWER(email) = ?', [$login])
            ->orWhereRaw('LOWER(username) = ?', [$login])
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->isArchived()) {
            return response()->json([
                'message' => 'This account has been archived. Please contact an administrator to restore your account.',
                'error'   => 'ACCOUNT_ARCHIVED',
            ], 403);
        }

        if ($user->isAdmin()) {
            return response()->json([
                'message' => 'Administrator accounts must sign in through the dedicated Admin Login portal (/admin/login).',
                'error'   => 'ADMIN_PORTAL_REQUIRED',
            ], 403);
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

    // POST /api/admin/login (Dedicated Admin & Super Admin login)
    public function adminLogin(Request $request)
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
                'email' => ['The provided administrator credentials are incorrect.'],
            ]);
        }

        if ($user->isArchived()) {
            return response()->json([
                'message' => 'This administrator account has been archived. Please contact a Super Admin.',
                'error'   => 'ACCOUNT_ARCHIVED',
            ], 403);
        }

        if (! $user->isAdmin()) {
            return response()->json([
                'message' => 'Access denied. Regular user accounts cannot sign in through the Admin Portal.',
                'error'   => 'FORAGER_NOT_ALLOWED',
            ], 403);
        }

        if ($this->requiresEmailVerification($user)) {
            return response()->json([
                'message'               => 'Please verify your email address before signing in. Check your inbox for the verification link.',
                'error'                 => 'EMAIL_NOT_VERIFIED',
                'requires_verification' => true,
                'email'                 => $user->email,
            ], 403);
        }

        $user->tokens()->delete();
        $token = $user->createToken('admin_auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Administrator login successful.',
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
        $user = $request->user();
        if ($user && $user->isArchived()) {
            $user->tokens()->delete();
            return response()->json([
                'message' => 'Your account has been archived.',
                'error'   => 'ACCOUNT_ARCHIVED',
            ], 403);
        }
        return response()->json($user);
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

    // POST /api/user/archive-request (auth:sanctum) — sends signed email link to authorize archiving
    public function requestArchiveAccount(Request $request)
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
                'message' => 'Admin accounts cannot be archived here. Contact a Super Admin.',
            ], 403);
        }

        $origin = rtrim((string) $request->headers->get('origin', ''), '/');
        $configuredFrontend = rtrim(trim(explode(',', (string) config('app.frontend_url'))[0] ?? 'http://localhost:3000'), '/');
        if ($configuredFrontend === '' || $configuredFrontend === 'http://localhost' || $configuredFrontend === 'http://127.0.0.1') {
            $configuredFrontend = 'http://localhost:3000';
        }
        $frontendBaseUrl = $origin !== '' ? $origin : $configuredFrontend;
        $id = $user->getKey();
        $hash = sha1($user->getEmailForVerification());

        $relativeSigned = URL::temporarySignedRoute(
            'user.archive.verify',
            now()->addMinutes(60),
            ['id' => $id, 'hash' => $hash],
            false
        );
        $query = parse_url($relativeSigned, PHP_URL_QUERY);

        $verificationUrl = $frontendBaseUrl
            . '/confirm-archive?'
            . http_build_query(['id' => $id, 'hash' => $hash])
            . ($query ? '&' . $query : '');

        Log::info('Account archive verification link generated', [
            'user_id' => $user->id,
            'email'   => $user->email,
            'url'     => $verificationUrl,
        ]);

        try {
            Mail::html(
                '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:520px;margin:0 auto;padding:24px;border:1px solid #e2e8f0;border-radius:16px;background:#ffffff;color:#0f172a;">'
                . '<h2 style="margin:0 0 12px;color:#065f46;">MycoVision — Confirm Account Archive</h2>'
                . '<p style="font-size:14px;line-height:1.6;color:#334155;">Hello <strong>' . e($user->name) . '</strong>,</p>'
                . '<p style="font-size:14px;line-height:1.6;color:#334155;">We received a request to archive your MycoVision account. Archiving your account will deactivate sign-in access while safely preserving your scan history and records.</p>'
                . '<p style="font-size:14px;line-height:1.6;color:#334155;">Click the button below within <strong>60 minutes</strong> to authorize and complete archiving your account:</p>'
                . '<div style="margin:24px 0;text-align:center;">'
                . '<a href="' . e($verificationUrl) . '" style="display:inline-block;padding:12px 24px;background:#d97706;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;border-radius:10px;">Authorize &amp; Archive Account</a>'
                . '</div>'
                . '<p style="font-size:12px;line-height:1.5;color:#64748b;">If the button does not work, copy and paste this link into your browser:<br><a href="' . e($verificationUrl) . '" style="color:#059669;word-break:break-all;">' . e($verificationUrl) . '</a></p>'
                . '<p style="font-size:12px;line-height:1.5;color:#64748b;margin-top:20px;">If you did not request this, you can safely ignore this email and your account will remain active.</p>'
                . '</div>',
                function ($message) use ($user) {
                    $message->to($user->email, $user->name)
                        ->subject('MycoVision — Authorize Account Archiving');
                }
            );
        } catch (\Throwable $e) {
            Log::error('Archive authorization email failed', ['message' => $e->getMessage()]);
            return response()->json([
                'message' => 'Could not send the authorization email. Please try again shortly.',
            ], 500);
        }

        return response()->json([
            'message'           => 'An authorization verification link has been sent to ' . $user->email . '. Please check your email and click the link to archive your account.',
            'email'             => $user->email,
            'requires_approval' => true,
        ]);
    }

    // GET /api/user/archive/verify/{id}/{hash} — verifies signed link and archives the user account
    public function verifyArchiveAccount(Request $request, int $id, string $hash)
    {
        if (! URL::hasValidRelativeSignature($request)) {
            return response()->json([
                'message' => 'This archive authorization link is invalid or has expired. Please request a new link from Settings.',
            ], 403);
        }

        $user = User::find($id);
        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), (string) $hash)) {
            return response()->json([
                'message' => 'Invalid archive authorization link.',
            ], 403);
        }

        try {
            if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'archived_at')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->timestamp('archived_at')->nullable();
                });
            }
        } catch (\Throwable) {
            // ignore
        }

        if (! $user->isArchived()) {
            DB::transaction(function () use ($user) {
                $user->subscriptions()->where('status', 'active')->get()->each(function ($sub) use ($user) {
                    $sub->update([
                        'auto_renew' => false,
                    ]);
                    SubscriptionEvent::log($user->id, 'cancelled', $sub->id, null, [
                        'reason' => 'account_archived_by_user',
                    ]);
                });

                $user->update(['archived_at' => now()]);
                $user->tokens()->delete();
            });
        } else {
            $user->tokens()->delete();
        }

        return response()->json([
            'message'  => 'Your account has been verified and archived. You may contact an administrator anytime if you wish to restore access.',
            'archived' => true,
        ]);
    }

    // DELETE /api/user (auth:sanctum) — delegates to archive request flow instead of deleting
    public function deleteAccount(Request $request)
    {
        return $this->requestArchiveAccount($request);
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
