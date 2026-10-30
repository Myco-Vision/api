<?php

namespace App\Http\Controllers;

use App\Models\RefundRequest;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Services\PayMongoService;
use App\Services\RefundPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Refund requests: users submit them, admins review them, PayMongo issues the money.
 */
class RefundController extends Controller
{
    public function __construct(
        protected RefundPolicyService $policy,
        protected PayMongoService $payMongo,
    ) {}

    // ── User side ──────────────────────────────────────────────────────────

    // GET /api/refunds — user's paid subscriptions with eligibility + their requests
    public function mine(Request $request)
    {
        $user = $request->user();

        $subs = $user->subscriptions()->latest('id')->take(10)->get()
            ->filter(fn ($s) => $this->policy->isPaid($s))
            ->map(fn ($s) => array_merge($s->only(['id', 'plan', 'price', 'currency', 'payment_method', 'starts_at', 'expires_at', 'status']), [
                'eligibility' => $this->policy->evaluate($s),
                'has_open_request' => RefundRequest::where('subscription_id', $s->id)->whereIn('status', ['pending', 'processing'])->exists(),
            ]))
            ->values();

        return response()->json([
            'subscriptions' => $subs,
            'requests'      => RefundRequest::where('user_id', $user->id)->with('subscription:id,plan,price,starts_at')->latest('id')->get(),
            'policy'        => [
                'guarantee_days'  => RefundPolicyService::GUARANTEE_DAYS,
                'scan_cap'        => RefundPolicyService::GUARANTEE_SCAN_CAP,
                'annual_days'     => RefundPolicyService::ANNUAL_PRORATE_DAYS,
                'annual_fee'      => RefundPolicyService::ANNUAL_PROCESSING_FEE,
                'support_email'   => RefundPolicyService::SUPPORT_EMAIL,
            ],
        ]);
    }

    // POST /api/refunds
    public function store(Request $request)
    {
        $data = $request->validate([
            'subscription_id' => 'required|integer',
            'reason_type'     => 'required|in:' . implode(',', RefundPolicyService::REASON_TYPES),
            'reason'          => 'required|string|min:10|max:2000',
        ]);

        $user = $request->user();
        $sub  = Subscription::where('id', $data['subscription_id'])->where('user_id', $user->id)->firstOrFail();

        if (!$this->policy->isPaid($sub)) {
            return response()->json(['message' => 'This subscription is not eligible for a refund.'], 422);
        }
        if (RefundRequest::where('subscription_id', $sub->id)->whereIn('status', ['pending', 'processing'])->exists()) {
            return response()->json(['message' => 'You already have an open refund request for this payment.'], 422);
        }

        $eval   = $this->policy->evaluate($sub);
        $option = $eval['options'][$data['reason_type']] ?? null;
        if (!$option) {
            $msg = match ($data['reason_type']) {
                'first_time_guarantee' => 'You are not eligible for the 7-day guarantee. ' . implode(' ', $eval['notes']),
                'annual_prorated'      => 'Prorated refunds are only for annual plans between day 7 and day 30.',
                default                => 'This refund reason does not apply to this subscription.',
            };
            return response()->json(['message' => trim($msg)], 422);
        }

        $refund = RefundRequest::create([
            'user_id'          => $user->id,
            'subscription_id'  => $sub->id,
            'reason_type'      => $data['reason_type'],
            'reason'           => $data['reason'],
            'requested_amount' => $option['amount'],
            'refund_type'      => $option['type'],
            'status'           => 'pending',
            'eligibility'      => $eval,
        ]);

        SubscriptionEvent::log($user->id, 'refund_requested', $sub->id, null, [
            'refund_request_id' => $refund->id, 'reason_type' => $data['reason_type'], 'amount' => $option['amount'],
        ]);

        return response()->json([
            'message' => 'Refund request submitted. We will reply within 3 business days.',
            'request' => $refund,
        ], 201);
    }

    // POST /api/refunds/{refund}/cancel — user withdraws a pending request
    public function withdraw(Request $request, RefundRequest $refund)
    {
        abort_unless($refund->user_id === $request->user()->id, 404);
        if ($refund->status !== 'pending') {
            return response()->json(['message' => 'Only pending requests can be withdrawn.'], 422);
        }
        $refund->update(['status' => 'withdrawn']);

        return response()->json(['message' => 'Refund request withdrawn.', 'request' => $refund]);
    }

    // ── Admin side ─────────────────────────────────────────────────────────

    // GET /api/admin/refunds
    public function index(Request $request)
    {
        $refunds = RefundRequest::with(['user:id,name,email,username', 'subscription', 'reviewer:id,name'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->search, fn ($q, $s) => $q->whereHas('user', fn ($u) => $u
                ->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")))
            ->latest('id')
            ->paginate((int) $request->input('per_page', 15));

        return response()->json($refunds);
    }

    // POST /api/admin/subscriptions/{subscription}/refund
    // Admin opens a request on the user's behalf (e.g. from a support email). It joins
    // the same pending queue, so the normal review / approve rules still apply.
    public function adminCreate(Request $request, Subscription $subscription)
    {
        $data = $request->validate([
            'reason_type' => 'required|in:' . implode(',', RefundPolicyService::REASON_TYPES),
            'reason'      => 'required|string|min:10|max:2000',
        ]);

        if (!$this->policy->isPaid($subscription)) {
            return response()->json(['message' => 'This subscription was not paid through the gateway or is already refunded, so it cannot be refunded.'], 422);
        }
        if (RefundRequest::where('subscription_id', $subscription->id)->whereIn('status', ['pending', 'processing'])->exists()) {
            return response()->json(['message' => 'There is already an open refund request for this payment.'], 422);
        }

        $eval = $this->policy->evaluate($subscription);
        // Admins may file any reason; the policy-computed amount is only a suggestion they can change on approval
        $option = $eval['options'][$data['reason_type']] ?? $eval['options']['other'];

        $refund = RefundRequest::create([
            'user_id'          => $subscription->user_id,
            'subscription_id'  => $subscription->id,
            'reason_type'      => $data['reason_type'],
            'reason'           => $data['reason'],
            'requested_amount' => $option['amount'],
            'refund_type'      => $option['type'],
            'status'           => 'pending',
            'eligibility'      => $eval,
        ]);

        SubscriptionEvent::log($subscription->user_id, 'refund_requested', $subscription->id, $request->user()->id, [
            'refund_request_id' => $refund->id, 'reason_type' => $data['reason_type'], 'amount' => $option['amount'], 'filed_by_admin' => true,
        ]);

        return response()->json([
            'message' => 'Refund request created. Review it in the Refunds queue.',
            'request' => $refund->load(['user:id,name,email,username', 'subscription', 'reviewer:id,name']),
        ], 201);
    }

    // GET /api/admin/refunds/stats
    public function stats()
    {
        return response()->json([
            'pending'        => RefundRequest::where('status', 'pending')->count(),
            'processing'     => RefundRequest::where('status', 'processing')->count(),
            'refunded'       => RefundRequest::where('status', 'refunded')->count(),
            'rejected'       => RefundRequest::where('status', 'rejected')->count(),
            'total_refunded' => round((float) RefundRequest::where('status', 'refunded')->sum('approved_amount'), 2),
        ]);
    }

    // POST /api/admin/refunds/{refund}/approve  (super admin only)
    public function approve(Request $request, RefundRequest $refund)
    {
        $data = $request->validate([
            'amount'      => 'required|numeric|min:1',
            'refund_type' => 'required|in:full,prorated',
            'note'        => 'nullable|string|max:1000',
        ]);

        if ($refund->status !== 'pending') {
            return response()->json(['message' => 'This request has already been reviewed.'], 422);
        }

        $sub = $refund->subscription;
        if (!$sub || !$this->policy->isPaid($sub)) {
            return response()->json(['message' => 'The subscription is no longer refundable.'], 422);
        }
        if ($data['amount'] > (float) $sub->price) {
            return response()->json(['message' => 'Refund amount cannot exceed the amount paid (₱' . number_format($sub->price, 2) . ').'], 422);
        }

        $refund->update(['status' => 'processing', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
            'approved_amount' => $data['amount'], 'refund_type' => $data['refund_type'], 'admin_note' => $data['note'] ?? null]);

        try {
            $result = $this->payMongo->createRefund(
                $sub->paymongo_payment_id,
                (float) $data['amount'],
                $refund->reason_type === 'duplicate_charge' ? 'duplicate' : 'requested_by_customer',
                "RefundRequest #{$refund->id}"
            );
        } catch (\Throwable $e) {
            Log::error("Refund #{$refund->id} failed: " . $e->getMessage());
            $refund->update(['status' => 'failed', 'admin_note' => trim(($data['note'] ?? '') . "\n[Gateway] " . $e->getMessage())]);
            return response()->json(['message' => $e->getMessage(), 'request' => $refund->fresh()], 502);
        }

        $refund->update(['paymongo_refund_id' => $result['id']]);

        if (($result['status'] ?? '') === 'succeeded') {
            $this->finalize($refund->fresh(), $request->user()->id);
        }
        // Otherwise stays "processing" until the refund.updated webhook confirms it.

        return response()->json([
            'message' => $refund->fresh()->status === 'refunded' ? 'Refund issued successfully.' : 'Refund submitted to PayMongo and is processing.',
            'request' => $refund->fresh()->load(['user:id,name,email', 'subscription', 'reviewer:id,name']),
        ]);
    }

    // POST /api/admin/refunds/{refund}/reject
    public function reject(Request $request, RefundRequest $refund)
    {
        $data = $request->validate(['note' => 'required|string|min:5|max:1000']);

        if ($refund->status !== 'pending') {
            return response()->json(['message' => 'This request has already been reviewed.'], 422);
        }

        $refund->update(['status' => 'rejected', 'admin_note' => $data['note'],
            'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);

        SubscriptionEvent::log($refund->user_id, 'refund_rejected', $refund->subscription_id, $request->user()->id, [
            'refund_request_id' => $refund->id, 'note' => $data['note'],
        ]);

        return response()->json(['message' => 'Refund request rejected.', 'request' => $refund->load(['user:id,name,email', 'subscription', 'reviewer:id,name'])]);
    }

    /**
     * Mark refund complete and apply the account effect from policy §6:
     * full refunds end Premium immediately; prorated refunds end it on processing.
     * Both cases end access now. Called from approve() and the webhook.
     */
    public static function finalize(RefundRequest $refund, ?int $adminId = null): void
    {
        if ($refund->status === 'refunded') return; // idempotent

        DB::transaction(function () use ($refund, $adminId) {
            $refund->update(['status' => 'refunded', 'refunded_at' => now()]);

            $sub = $refund->subscription;
            $isFull = (float) $refund->approved_amount >= (float) $sub->price;

            $sub->update([
                'status'         => 'cancelled',
                'auto_renew'     => false,
                'cancelled_at'   => $sub->cancelled_at ?? now(),
                'expires_at'     => now(),
                'payment_status' => $isFull ? 'refunded' : 'partially_refunded',
            ]);

            SubscriptionEvent::log($refund->user_id, 'refunded', $sub->id, $adminId ?? $refund->reviewed_by, [
                'refund_request_id'  => $refund->id,
                'amount'             => $refund->approved_amount,
                'type'               => $refund->refund_type,
                'paymongo_refund_id' => $refund->paymongo_refund_id,
            ]);
        });
    }
}
