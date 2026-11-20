<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    public const FREE_SCAN_LIMIT = 3;

    public static function freeScanLimit(): int
    {
        return \App\Services\PlanConfigService::freeScanLimit();
    }

    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'username',
        'email',
        'google_id',
        'needs_profile_completion',
        'contact_number',
        'address',
        'password',
        'role',
        'profile_photo',
        'allow_data_training',
        'data_training_consented_at',
        'reward_points',
        'pending_points',
        'verified_points',
        'lifetime_free_scans_used',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = [
        'subscription_tier',
        'is_subscribed',
        'remaining_scans',
        'can_scan',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'        => 'datetime',
            'password'                 => 'hashed',
            'needs_profile_completion' => 'boolean',
            'reward_points'            => 'integer',
            'pending_points'           => 'integer',
            'verified_points'          => 'integer',
            'lifetime_free_scans_used' => 'integer',
        ];
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'super_admin']);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function scans()
    {
        return $this->hasMany(Scan::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function refundRequests()
    {
        return $this->hasMany(RefundRequest::class);
    }

    public function pointTransactions()
    {
        return $this->hasMany(PointTransaction::class);
    }

    public function mycodex()
    {
        return $this->hasMany(UserMycodex::class);
    }

    public function scanContributions()
    {
        return $this->hasMany(ScanContribution::class);
    }

    public function activeSubscription()
    {
        return $this->hasOne(Subscription::class)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->latest('id');
    }

    public function hasActiveSubscription(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->subscriptions()
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->exists();
    }

    public function freeScansUsed(): int
    {
        return (int) ($this->lifetime_free_scans_used ?? $this->scans()->count());
    }

    public function remainingFreeScans(): int
    {
        if ($this->hasActiveSubscription()) {
            return -1; // -1 represents unlimited
        }

        $used = $this->freeScansUsed();
        return max(0, self::freeScanLimit() - $used);
    }

    public function canScan(): bool
    {
        if ($this->hasActiveSubscription()) {
            return true;
        }

        return $this->freeScansUsed() < self::freeScanLimit();
    }

    public function getSubscriptionTierAttribute(): string
    {
        if ($this->isAdmin()) {
            return 'admin';
        }

        $active = $this->activeSubscription;
        if ($active) {
            return $active->plan; // 'monthly' or 'annual'
        }

        return 'free';
    }

    public function getIsSubscribedAttribute(): bool
    {
        return $this->hasActiveSubscription();
    }

    public function getRemainingScansAttribute(): int
    {
        return $this->remainingFreeScans();
    }

    public function getCanScanAttribute(): bool
    {
        return $this->canScan();
    }
}
