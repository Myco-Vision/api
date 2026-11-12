<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $frontendBaseUrl = fn () => rtrim(trim(explode(',', (string) config('app.frontend_url'))[0] ?? ''), '/');

        // Point password-reset links at the Nuxt frontend instead of the API.
        ResetPassword::createUrlUsing(function ($user, string $token) use ($frontendBaseUrl) {
            return $frontendBaseUrl()
                . '/reset-password?token=' . urlencode($token)
                . '&email=' . urlencode($user->email);
        });

        // Point email-verification links at the Nuxt frontend while preserving the
        // HMAC-signed relative query parameters (expires & signature) for /api/email/verify.
        VerifyEmail::createUrlUsing(function ($notifiable) use ($frontendBaseUrl) {
            $id = $notifiable->getKey();
            $hash = sha1($notifiable->getEmailForVerification());

            $relativeSigned = URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes(60),
                ['id' => $id, 'hash' => $hash],
                false
            );
            $query = parse_url($relativeSigned, PHP_URL_QUERY);

            return $frontendBaseUrl()
                . '/verify-email?'
                . http_build_query(['id' => $id, 'hash' => $hash])
                . ($query ? '&' . $query : '');
        });
    }
}
