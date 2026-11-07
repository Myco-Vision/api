<?php

namespace App\Services;

use App\Models\RefundRequest;
use App\Models\Subscription;
use App\Models\User;

/**
 * Server-side refund eligibility rules, mirroring the public Refund Policy.
 * All tunable values live in the constants below.
 */
class RefundPolicyService
{
    public const GUARANTEE_DAYS        = 7;    // first-time 7-day guarantee window
    public const GUARANTEE_SCAN_CAP    = 20;   // max premium scans used to qualify
    public const ANNUAL_PRORATE_DAYS   = 30;   // annual prorated refund window
    public const ANNUAL_PROCESSING_FEE = 100;  // ₱ deducted from prorated annual refunds
    public const SERVICE_FAILURE_HOURS = 72;
    public const SUPPORT_EMAIL         = 'support@mycovision.app';

    public const REASON_TYPES = [
        'first_time_guarantee',
        'duplicate_charge',
        'service_failure',
        'charged_after_cancel',
        'annual_prorated',
        'other',
    ];

    /** A subscription that was actually paid through the gateway (not a grant / pending). */
    public function isPaid(Subscription $s): bool
    {
        return $s->payment_method !== 'admin_grant'
            && $s->payment_status !== 'complimentary'
            && !empty($s->paymongo_payment_id)
            && (float) $s->price > 0
            && in_array($s->status, ['active', 'cancelled', 'expired'], true)
            && !in_array($s->payment_status, ['refunded', 'partially_refunded'], true);
    }

    /** Scans the user ran while this subscription was in effect. */
    public function premiumScansUsed(Subscription $s): int
    {
        if (!$s->starts_at) return 0;

        return $s->user->scans()
            ->where('created_at', '>=', $s->starts_at)
            ->when($s->expires_at, fn ($q) => $q->where('created_at', '<=', $s->expires_at))
            ->count();
    }

    public function isFirstPaidSubscription(Subscription $s): bool
    {
        return !Subscription::where('user_id', $s->user_id)
            ->where('id', '<', $s->id)
            ->whereNotNull('paymongo_payment_id')
            ->where('price', '>', 0)
            ->where(fn ($q) => $q->whereNull('payment_method')->orWhere('payment_method', '!=', 'admin_grant'))
            ->exists();
    }

    public function hasPriorGuaranteeRefund(User $user): bool
    {
        return RefundRequest::where('user_id', $user->id)
            ->where('reason_type', 'first_time_guarantee')
            ->whereIn('status', ['approved', 'processing', 'refunded'])
            ->exists();
    }

    /** refund = price × (unused full months ÷ 12) − fee, minimum ₱0 */
    public function annualProratedAmount(Subscription $s): float
    {
        if (!$s->expires_at || $s->expires_at->isPast()) return 0.0;

        $unusedMonths = (int) floor(now()->diffInMonths($s->expires_at));
        $amount = (float) $s->price * ($unusedMonths / 12) - self::ANNUAL_PROCESSING_FEE;

        return round(max(0, $amount), 2);
    }

    /** Remaining-time proration used for service-failure refunds. */
    public function remainingTimeAmount(Subscription $s): float
    {
        if (!$s->starts_at || !$s->expires_at || $s->expires_at->isPast()) return 0.0;

        $total = max(1, $s->starts_at->diffInSeconds($s->expires_at));
        $left  = now()->diffInSeconds($s->expires_at);

        return round((float) $s->price * ($left / $total), 2);
    }

    /**
     * Evaluate every rule for a subscription. Returns a snapshot used both to
     * show the user their options and to store with the request for admins.
     */
    public function evaluate(Subscription $s): array
    {
        $s->loadMissing('user');

        $paid         = $this->isPaid($s);
        $daysSince    = $s->starts_at ? (int) floor($s->starts_at->diffInHours(now()) / 24) : null;
        $scansUsed    = $this->premiumScansUsed($s);
        $isFirst      = $this->isFirstPaidSubscription($s);
        $priorRefund  = $this->hasPriorGuaranteeRefund($s->user);
        $price        = (float) $s->price;
        $inGuarantee  = $daysSince !== null && $daysSince < self::GUARANTEE_DAYS;

        $guaranteeOk  = $paid && $inGuarantee && $isFirst && !$priorRefund && $scansUsed <= self::GUARANTEE_SCAN_CAP;
        $annualOk     = $paid && $s->plan === 'annual' && $daysSince !== null
            && $daysSince >= self::GUARANTEE_DAYS && $daysSince < self::ANNUAL_PRORATE_DAYS
            && $this->annualProratedAmount($s) > 0;

        $options = [];
        if ($guaranteeOk) {
            $options['first_time_guarantee'] = ['type' => 'full', 'amount' => $price, 'auto_eligible' => true];
        }
        if ($annualOk) {
            $options['annual_prorated'] = ['type' => 'prorated', 'amount' => $this->annualProratedAmount($s), 'auto_eligible' => true];
        }
        if ($paid) {
            // These depend on facts only an admin can confirm, so they are always reviewable
            $options['duplicate_charge']     = ['type' => 'full',     'amount' => $price, 'auto_eligible' => false];
            $options['charged_after_cancel'] = ['type' => 'full',     'amount' => $price, 'auto_eligible' => false];
            $options['service_failure']      = ['type' => 'prorated', 'amount' => $this->remainingTimeAmount($s), 'auto_eligible' => false];
            $options['other']                = ['type' => 'full',     'amount' => 0, 'auto_eligible' => false];
        }

        $blockers = [];
        if (!$paid)                          $blockers[] = 'This subscription was not paid through our payment gateway, or was already refunded.';
        if ($paid && !$inGuarantee)          $blockers[] = 'The ' . self::GUARANTEE_DAYS . '-day guarantee window has passed.';
        if ($paid && !$isFirst)              $blockers[] = 'The 7-day guarantee applies to your first Premium purchase only.';
        if ($paid && $priorRefund)           $blockers[] = 'You have already used the 7-day guarantee.';
        if ($paid && $scansUsed > self::GUARANTEE_SCAN_CAP) $blockers[] = 'More than ' . self::GUARANTEE_SCAN_CAP . ' premium scans were used.';

        return [
            'subscription_id'      => $s->id,
            'plan'                 => $s->plan,
            'price'                => $price,
            'paid'                 => $paid,
            'days_since_purchase'  => $daysSince,
            'premium_scans_used'   => $scansUsed,
            'scan_cap'             => self::GUARANTEE_SCAN_CAP,
            'is_first_purchase'    => $isFirst,
            'prior_guarantee_used' => $priorRefund,
            'guarantee_eligible'   => $guaranteeOk,
            'annual_prorated_eligible' => $annualOk,
            'options'              => $options,
            'notes'                => $guaranteeOk ? [] : $blockers,
            'evaluated_at'         => now()->toIso8601String(),
        ];
    }
}
