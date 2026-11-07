<?php

namespace App\Http\Controllers;

use App\Models\RefundRequest;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Services\PayMongoService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    protected PayMongoService $payMongoService;

    public function __construct(PayMongoService $payMongoService)
    {
        $this->payMongoService = $payMongoService;
    }

    /**
     * GET /api/subscription/plans
     * Public / Authenticated plans listing
     */
    public function plans()
    {
        return response()->json([
            'free_tier' => [
                'name'        => 'Free Starter',
                'price'       => 0,
                'scans_limit' => User::FREE_SCAN_LIMIT,
                'renewable'   => false,
                'description' => '3 lifetime AI mushroom scans with permanent access to saved data',
                'features'    => [
                    '3 AI mushroom scans included',
                    'Full access to all your saved scans & history',
                    'Full access to personal collection',
                    'Access to mushroom species directory',
                    'Basic classification details',
                ],
            ],
            'plans' => $this->payMongoService->getPlans(),
        ]);
    }

    /**
     * GET /api/subscription/status
     * Returns current user's scan usage and subscription details
     */
    public function status(Request $request)
    {
        $user = $request->user();

        // Auto-sync any pending PayMongo subscriptions so completed payments activate immediately
        $pendingSubs = $user->subscriptions()
            ->where('status', 'pending')
            ->whereNotNull('paymongo_session_id')
            ->latest()
            ->get();

        foreach ($pendingSubs as $pending) {
            try {
                $sessionData = $this->payMongoService->getCheckoutSession($pending->paymongo_session_id);
                if ($sessionData['paid'] ?? false) {
                    $planKey = $pending->plan ?? 'monthly';
                    $startsAt = Carbon::now();
                    $expiresAt = $planKey === 'annual' ? Carbon::now()->addYear() : Carbon::now()->addMonth();

                    $pending->update([
                        'status'              => 'active',
                        'price'               => ($sessionData['amount'] ?? 0) > 0 ? $sessionData['amount'] : $pending->price,
                        'currency'            => $sessionData['currency'] ?? 'PHP',
                        'paymongo_payment_id' => $sessionData['payment_id'] ?? null,
                        'payment_method'      => $sessionData['payment_method'] ?? $pending->payment_method,
                        'starts_at'           => $startsAt,
                        'expires_at'          => $expiresAt,
                        'metadata'            => array_merge($pending->metadata ?? [], [
                            'verified_at' => Carbon::now()->toIso8601String(),
                            'auto_synced' => true,
                            'session'     => $sessionData,
                        ]),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning("Auto-sync pending subscription {$pending->paymongo_session_id} skipped: " . $e->getMessage());
            }
        }

        // Refresh model relations
        $user->unsetRelation('activeSubscription');
        $user->unsetRelation('subscriptions');

        $scansUsed = $user->scans()->count();
        $activeSub = $user->activeSubscription;

        $daysRemaining = null;
        if ($activeSub && $activeSub->expires_at) {
            $daysRemaining = max(0, Carbon::now()->diffInDays($activeSub->expires_at, false));
        }

        $history = $user->subscriptions()
            ->latest()
            ->take(10)
            ->get();

        return response()->json([
            'user_id'             => $user->id,
            'role'                => $user->role,
            'subscription_tier'   => $user->subscription_tier,
            'is_subscribed'       => $user->is_subscribed,
            'can_scan'            => $user->can_scan,
            'free_limit'          => User::FREE_SCAN_LIMIT,
            'scans_used'          => $scansUsed,
            'remaining_scans'     => $user->remaining_scans, // -1 means unlimited
            'active_subscription' => $activeSub ? [
                'id'             => $activeSub->id,
                'plan'           => $activeSub->plan,
                'status'         => $activeSub->status,
                'price'          => $activeSub->price,
                'currency'       => $activeSub->currency,
                'payment_method' => $activeSub->payment_method,
                'starts_at'      => $activeSub->starts_at?->toIso8601String(),
                'expires_at'     => $activeSub->expires_at?->toIso8601String(),
                'days_remaining' => $daysRemaining,
                'is_cancelled'   => !is_null($activeSub->cancelled_at),
                'cancelled_at'   => $activeSub->cancelled_at?->toIso8601String(),
            ] : null,
            'history' => $history,
        ]);
    }

    /**
     * POST /api/subscription/checkout
     * Initiates PayMongo checkout session for selected plan
     */
    public function checkout(Request $request)
    {
        $data = $request->validate([
            'plan'           => 'required|string|in:monthly,annual',
            'payment_method' => 'nullable|string|in:gcash',
            'success_url'    => 'nullable|string',
            'cancel_url'     => 'nullable|string',
        ]);

        $user = $request->user();
        $planKey = $data['plan'];
        $planDetails = $this->payMongoService->getPlan($planKey);

        if (!$planDetails) {
            return response()->json(['message' => 'Invalid subscription plan selected.'], 422);
        }

        try {
            $session = $this->payMongoService->createCheckoutSession(
                $user,
                $planKey,
                $data['success_url'] ?? null,
                $data['cancel_url'] ?? null,
                $data['payment_method'] ?? 'all'
            );

            // Record pending subscription in DB
            Subscription::create([
                'user_id'             => $user->id,
                'plan'                => $planKey,
                'status'              => 'pending',
                'price'               => $planDetails['amount'],
                'currency'            => $planDetails['currency'],
                'payment_method'      => $session['payment_method'] ?? ($data['payment_method'] ?? 'gcash'),
                'paymongo_session_id' => $session['session_id'],
                'metadata'            => [
                    'mode'       => $session['mode'] ?? 'standard',
                    'ip_address' => $request->ip(),
                ],
            ]);

            return response()->json([
                'checkout_url' => $session['checkout_url'],
                'session_id'   => $session['session_id'],
                'plan'         => $planDetails,
                'mode'         => $session['mode'] ?? 'standard',
            ]);
        } catch (\Exception $e) {
            Log::error('Checkout initiation failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'Unable to create checkout session. ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/subscription/verify
     * Verifies completed PayMongo checkout session and activates subscription
     */
    public function verify(Request $request)
    {
        $data = $request->validate([
            'session_id' => 'nullable|string',
            'plan'       => 'nullable|string|in:monthly,annual',
        ]);

        $user = $request->user();
        $sessionId = $data['session_id'] ?? null;

        if (!$sessionId) {
            $latestPending = $user->subscriptions()->where('status', 'pending')->latest()->first();
            $sessionId = $latestPending?->paymongo_session_id;
        }

        if (!$sessionId) {
            // Check if user already has an active subscription recently activated
            $activeSub = $user->activeSubscription;
            if ($activeSub) {
                return response()->json([
                    'message'      => 'Your subscription has been activated successfully! Unlimited mushroom scans unlocked.',
                    'subscription' => $activeSub,
                    'user'         => $user->fresh(),
                ]);
            }
            return response()->json(['message' => 'No checkout session found to verify.'], 400);
        }

        try {
            // If already active in database, return immediately
            $existing = Subscription::where('paymongo_session_id', $sessionId)
                ->where('user_id', $user->id)
                ->first();

            if ($existing && $existing->status === 'active') {
                return response()->json([
                    'message'      => 'Your subscription has been activated successfully! Unlimited mushroom scans unlocked.',
                    'subscription' => $existing,
                    'user'         => $user->fresh(),
                ]);
            }

            $sessionData = $this->payMongoService->getCheckoutSession($sessionId);

            if (!($sessionData['paid'] ?? false)) {
                return response()->json([
                    'message' => 'Payment has not been completed yet.',
                    'status'  => $sessionData['status'] ?? 'pending',
                ], 400);
            }

            // Find existing subscription record or create a new active one
            $subscription = Subscription::where('paymongo_session_id', $sessionId)
                ->where('user_id', $user->id)
                ->first();

            $planKey = $data['plan'] ?? ($subscription?->plan ?? 'monthly');
            $planDetails = $this->payMongoService->getPlan($planKey) ?? $this->payMongoService->getPlan('monthly');

            $durationMonths = $planKey === 'annual' ? 12 : 1;
            $startsAt = Carbon::now();
            $expiresAt = $planKey === 'annual' ? Carbon::now()->addYear() : Carbon::now()->addMonth();

            if ($subscription) {
                $subscription->update([
                    'plan'                   => $planKey,
                    'status'                 => 'active',
                    'price'                  => $sessionData['amount'] > 0 ? $sessionData['amount'] : $planDetails['amount'],
                    'currency'               => $sessionData['currency'] ?? 'PHP',
                    'paymongo_payment_id'    => $sessionData['payment_id'] ?? null,
                    'payment_method'         => $sessionData['payment_method'] ?? 'paymongo',
                    'starts_at'              => $startsAt,
                    'expires_at'             => $expiresAt,
                    'metadata'               => array_merge($subscription->metadata ?? [], [
                        'verified_at' => Carbon::now()->toIso8601String(),
                        'session'     => $sessionData,
                    ]),
                ]);
            } else {
                $subscription = Subscription::create([
                    'user_id'                => $user->id,
                    'plan'                   => $planKey,
                    'status'                 => 'active',
                    'price'                  => $sessionData['amount'] > 0 ? $sessionData['amount'] : $planDetails['amount'],
                    'currency'               => $sessionData['currency'] ?? 'PHP',
                    'paymongo_session_id'    => $sessionId,
                    'paymongo_payment_id'    => $sessionData['payment_id'] ?? null,
                    'payment_method'         => $sessionData['payment_method'] ?? 'paymongo',
                    'starts_at'              => $startsAt,
                    'expires_at'             => $expiresAt,
                    'metadata'               => [
                        'verified_at' => Carbon::now()->toIso8601String(),
                        'session'     => $sessionData,
                    ],
                ]);
            }

            return response()->json([
                'message'      => 'Your subscription has been activated successfully! Unlimited mushroom scans unlocked.',
                'subscription' => $subscription,
                'user'         => $user->fresh(),
            ]);
        } catch (\Exception $e) {
            Log::error('Subscription verification failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'Verification failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/subscription/cancel
     * Cancels auto-renewal for current active subscription
     */
    public function cancel(Request $request)
    {
        $user = $request->user();
        $activeSub = $user->activeSubscription;

        if (!$activeSub) {
            return response()->json(['message' => 'No active subscription found to cancel.'], 404);
        }

        $activeSub->update([
            'cancelled_at' => Carbon::now(),
        ]);

        return response()->json([
            'message'      => 'Your subscription auto-renewal has been cancelled. You retain full access until ' . $activeSub->expires_at->format('M d, Y') . '.',
            'subscription' => $activeSub,
        ]);
    }

    /**
     * POST /api/paymongo/webhook
     * Handles webhook callbacks directly from PayMongo
     */
    public function webhook(Request $request)
    {
        // ── Verify the request really came from PayMongo ────────────────────
        $secret = config('services.paymongo.webhook_secret');
        if (!empty($secret)) {
            if (!$this->isValidWebhookSignature($request, $secret)) {
                Log::warning('PayMongo webhook rejected: invalid signature');
                return response()->json(['message' => 'Invalid signature.'], 400);
            }
        } else {
            Log::warning('PAYMONGO_WEBHOOK_SECRET is empty — webhook signature NOT verified.');
        }

        $payload = $request->all();
        $event = $payload['data']['attributes']['type'] ?? null;

        Log::info('PayMongo Webhook event received: ' . $event, ['payload' => $payload]);

        if ($event === 'checkout_session.payment.paid') {
            $checkoutSession = $payload['data']['attributes']['data'] ?? [];
            $sessionId = $checkoutSession['id'] ?? null;
            $payments = $checkoutSession['attributes']['payments'] ?? [];

            if ($sessionId) {
                $sub = Subscription::where('paymongo_session_id', $sessionId)->first();

                // Idempotent: PayMongo retries deliveries, so never re-activate an active subscription
                if ($sub && $sub->status !== 'active') {
                    $planKey = $sub->plan;
                    $startsAt = Carbon::now();
                    $expiresAt = $planKey === 'annual' ? Carbon::now()->addYear() : Carbon::now()->addMonth();

                    $sub->update([
                        'status'              => 'active',
                        'paymongo_payment_id' => $payments[0]['id'] ?? null,
                        'payment_method'      => $payments[0]['attributes']['source']['type'] ?? 'gcash',
                        'starts_at'           => $startsAt,
                        'expires_at'          => $expiresAt,
                    ]);
                }
            }
        }

        // ── Refund confirmations ────────────────────────────────────────────
        // payment.refund.updated → data is the refund resource (ref_…)
        // payment.refunded       → data is the refunded payment resource (pay_…)
        if (in_array($event, ['payment.refund.updated', 'payment.refunded'], true)) {
            try {
                $this->handleRefundEvent($event, $payload['data']['attributes']['data'] ?? []);
            } catch (\Throwable $e) {
                // Never fail the webhook: PayMongo would keep retrying a bad payload forever
                Log::error('Refund webhook handling failed: ' . $e->getMessage());
            }
        }

        // Always return 2xx quickly, otherwise PayMongo keeps retrying
        return response()->json(['status' => 'success']);
    }

    /**
     * Settle a 'processing' refund request from a PayMongo refund webhook.
     * Idempotent: RefundController::finalize() ignores already-refunded requests.
     */
    protected function handleRefundEvent(string $event, array $resource): void
    {
        $id     = $resource['id'] ?? null;
        $type   = $resource['type'] ?? null;
        $status = $resource['attributes']['status'] ?? null;

        if (!$id) {
            return;
        }

        $refund = null;

        if ($type === 'refund') {
            $refund = RefundRequest::where('paymongo_refund_id', $id)->first();
        }

        // payment.refunded can arrive before approve() has saved the refund id,
        // so fall back to the one request currently processing for that payment.
        if (!$refund) {
            $paymentId = $type === 'refund' ? ($resource['attributes']['payment_id'] ?? null) : $id;
            if ($paymentId) {
                $refund = RefundRequest::where('status', 'processing')
                    ->whereHas('subscription', fn ($q) => $q->where('paymongo_payment_id', $paymentId))
                    ->latest('id')
                    ->first();
            }
        }

        if (!$refund) {
            Log::info("Refund webhook {$event}: no matching refund request for {$id}");
            return;
        }

        if ($refund->status !== 'processing') {
            return; // already settled (or never approved) — nothing to do
        }

        $failed    = $type === 'refund' && $status === 'failed';
        $succeeded = $event === 'payment.refunded' || $status === 'succeeded';

        if ($failed) {
            $refund->update([
                'status'     => 'failed',
                'admin_note' => trim(($refund->admin_note ?? '') . "\n[Gateway] PayMongo reported the refund as failed."),
            ]);
            SubscriptionEvent::log($refund->user_id, 'refund_failed', $refund->subscription_id, $refund->reviewed_by, [
                'refund_request_id' => $refund->id,
            ]);
            return;
        }

        if ($succeeded) {
            if ($type === 'refund' && !$refund->paymongo_refund_id) {
                $refund->update(['paymongo_refund_id' => $id]);
            }
            RefundController::finalize($refund->fresh());
        }
    }

    /**
     * Validate the Paymongo-Signature header: "t=<timestamp>,te=<test_sig>,li=<live_sig>"
     * signature = HMAC-SHA256("<t>.<raw body>", webhook secret)
     */
    protected function isValidWebhookSignature(Request $request, string $secret): bool
    {
        $header = (string) $request->header('Paymongo-Signature', '');
        if ($header === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $piece) {
            [$key, $value] = array_pad(explode('=', $piece, 2), 2, null);
            $parts[trim((string) $key)] = trim((string) $value);
        }

        $timestamp = $parts['t'] ?? null;
        if (!$timestamp) {
            return false;
        }

        $computed = hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $secret);

        // 'te' = test-mode signature, 'li' = live-mode signature
        $expected = str_starts_with((string) config('services.paymongo.secret_key'), 'sk_live_')
            ? ($parts['li'] ?? '')
            : ($parts['te'] ?? '');

        return $expected !== '' && hash_equals($computed, $expected);
    }
}
