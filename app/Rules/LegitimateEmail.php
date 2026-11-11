<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class LegitimateEmail implements ValidationRule
{
    /**
     * Common disposable, throwaway, and reserved dummy domains.
     */
    private const BLOCKED_DOMAINS = [
        'example.com',
        'example.org',
        'example.net',
        'test.com',
        'test.org',
        'test.net',
        'localhost',
        'invalid',
        'fake.com',
        'dummy.com',
        'asdf.com',
        'mailinator.com',
        'yopmail.com',
        'tempmail.com',
        'temp-mail.org',
        'temp-mail.io',
        'guerrillamail.com',
        'guerrillamail.org',
        'guerrillamail.net',
        'sharklasers.com',
        '10minutemail.com',
        '10minutemail.net',
        'trashmail.com',
        'getnada.com',
        'dispostable.com',
        'maildrop.cc',
        'fakeinbox.com',
        'throwawaymail.com',
        'mohmal.com',
        'emailondeck.com',
        'mintemail.com',
        'mytemp.email',
        'mailnesia.com',
        'burnermail.io',
        'spamgourmet.com',
        'discard.email',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $email = strtolower(trim((string) $value));
        $parts = explode('@', $email);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            $fail('Please enter a valid email address.');
            return;
        }

        $domain = rtrim($parts[1], '.');

        if (! str_contains($domain, '.') || in_array($domain, self::BLOCKED_DOMAINS, true)) {
            $fail('Please use a real, permanent email address (dummy or disposable emails are not allowed).');
            return;
        }
    }
}

