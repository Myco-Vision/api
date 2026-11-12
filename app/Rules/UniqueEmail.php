<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UniqueEmail implements ValidationRule
{
    public function __construct(
        private readonly ?int $ignoreUserId = null
    ) {}

    public static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Canonicalize an email address for duplicate comparison.
     * Treats Gmail / Googlemail dot-variants and +tag aliases as the same inbox.
     */
    public static function canonicalize(string $email): string
    {
        $normalized = self::normalize($email);
        $parts = explode('@', $normalized, 2);
        if (count($parts) !== 2) {
            return $normalized;
        }

        [$local, $domain] = $parts;
        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $domain = 'gmail.com';
            $local = explode('+', $local, 2)[0];
            $local = str_replace('.', '', $local);
        }

        return "{$local}@{$domain}";
    }

    /**
     * Case-insensitive + Gmail-alias-aware uniqueness check against users.email.
     */
    public static function existsInUsers(string $email, ?int $ignoreUserId = null): bool
    {
        $normalized = self::normalize($email);
        if ($normalized === '') {
            return false;
        }

        $exactQuery = User::whereRaw('LOWER(email) = ?', [$normalized]);
        if ($ignoreUserId !== null) {
            $exactQuery->where('id', '!=', $ignoreUserId);
        }
        if ($exactQuery->exists()) {
            return true;
        }

        $parts = explode('@', $normalized, 2);
        if (count($parts) === 2 && in_array($parts[1], ['gmail.com', 'googlemail.com'], true)) {
            $targetCanonical = self::canonicalize($normalized);

            $gmailQuery = User::query()
                ->where(function ($q) {
                    $q->whereRaw("LOWER(email) LIKE '%@gmail.com'")
                      ->orWhereRaw("LOWER(email) LIKE '%@googlemail.com'");
                });
            if ($ignoreUserId !== null) {
                $gmailQuery->where('id', '!=', $ignoreUserId);
            }

            foreach ($gmailQuery->pluck('email') as $existingEmail) {
                if (self::canonicalize((string) $existingEmail) === $targetCanonical) {
                    return true;
                }
            }
        }

        return false;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::existsInUsers((string) $value, $this->ignoreUserId)) {
            $fail('This email address is already registered. Please use a different email.');
        }
    }
}

