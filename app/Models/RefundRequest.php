<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RefundRequest extends Model
{
    protected $fillable = [
        'user_id',
        'subscription_id',
        'reason_type',
        'reason',
        'requested_amount',
        'approved_amount',
        'refund_type',
        'status',
        'eligibility',
        'admin_note',
        'reviewed_by',
        'reviewed_at',
        'paymongo_refund_id',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_amount' => 'float',
            'approved_amount'  => 'float',
            'eligibility'      => 'array',
            'reviewed_at'      => 'datetime',
            'refunded_at'      => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'processing'], true);
    }
}
