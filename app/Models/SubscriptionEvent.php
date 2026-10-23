<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionEvent extends Model
{
    protected $fillable = ['user_id', 'subscription_id', 'admin_id', 'action', 'details'];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public static function log(int $userId, string $action, ?int $subscriptionId = null, ?int $adminId = null, array $details = []): self
    {
        return static::create([
            'user_id'         => $userId,
            'subscription_id' => $subscriptionId,
            'admin_id'        => $adminId,
            'action'          => $action,
            'details'         => $details ?: null,
        ]);
    }
}
