<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'plan',
        'status',
        'auto_renew',
        'payment_status',
        'price',
        'currency',
        'paymongo_session_id',
        'paymongo_payment_id',
        'payment_method',
        'starts_at',
        'expires_at',
        'cancelled_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'price'        => 'float',
            'starts_at'    => 'datetime',
            'expires_at'   => 'datetime',
            'cancelled_at' => 'datetime',
            'metadata'     => 'array',
            'auto_renew'   => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->expires_at && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}
