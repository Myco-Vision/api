<?php

namespace App\Http\Controllers;

use App\Models\RefundRequest;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Services\PayMongoService;
use App\Services\RewardService;
use App\Services\TransactionEmailService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    protected PayMongoService $payMongoService;
    protected RewardService $rewardService;
    protected TransactionEmailService $transactionEmails;

    public function __construct(
        PayMongoService $payMongoService,
        RewardService $rewardService,
        TransactionEmailService $transactionEmails
    ) {
        $this->payMongoService = $payMongoService;
        $this->rewardService = $rewardService;
        $this->transactionEmails = $transactionEmails;
    }

    /**
     * GET /api/subscription/plans
     * Public / Authenticated plans listing
     */
    public function plans()
    {
        $freeConfig = \App\Services\PlanConfigService::all()['free'] ?? [];
        $freeLimit = \App\Services\PlanConfigService::freeScanLimit();

        return response()->json([
            'free_tier' => [
                'name'        => $freeConfig['name'] ?? 'Free Starter',
                'price'       => 0,
                'scans_limit' => $freeLimit,
                'renewable'   => false,
                'description' => $freeConfig['description'] ?? "{$freeLimit} lifetime AI mushroom scans with permanent access to saved data",
                'features'    => $freeConfig['features'] ?? [
                    "{$freeLimit} AI mushroom scans included",
                    'Full access to all your saved scans & history',
                    'Full access to personal collection',
                    'Access to mushroom species directory',
                    'Basic classification details',
                ],
                'flags'       => $freeConfig['flags'] ?? [],
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
                    [$activated, $justActivated] = $this->activatePaidSubscription($pending, $user->id, array_merge($sessionData, [
                        'session_id' => $pending->paymongo_session_id,
                        'source'     => 'status_auto_sync',
                    ]));
                    if ($justActivated) {
                        $this->handlePostActivation($activated);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("Auto-sync pending subscription {$pending->paymongo_session_id} skipped: " . $e->getMessage());
            }
        }

        // Refresh model relations
        $user->unsetRelation('activeSubscription');
        $user->unsetRelation('subscriptions');

        $scansUsed = $user->scans()->count();
        $freeScansUsed = $user->freeScansUsed();
        $freeLimit = \App\Services\PlanConfigService::freeScanLimit();
        $activeSub = $user->activeSubscription;

        $daysRemaining = null;
        if ($activeSub && $activeSub->expires_at) {
            $daysRemaining = (int) ceil(max(0, Carbon::now()->diffInDays($activeSub->expires_at, false)));
        }

        $history = $user->subscriptions()
            ->latest()
            ->take(10)
            ->get();

        $mycodex = [
            'latest_specimen_id'   => 0,
            'latest_specimen_name' => null,
            'total_species'        => 0,
            'new_specimens_count'  => 0,
        ];
        if (\Illuminate\Support\Facades\Schema::hasTable('species')) {
            $mycodex = [
                'latest_specimen_id'   => (int) (\App\Models\Species::where('is_active_mycodex', true)->max('id') ?? 0),
                'latest_specimen_name' => \App\Models\Species::where('is_active_mycodex', true)->latest('id')->value('name'),
                'total_species'        => (int) \App\Models\Species::where('is_active_mycodex', true)->count(),
                'new_specimens_count'  => (int) (\App\Models\Species::where('is_active_mycodex', true)->where('created_at', '>=', now()->subDays(7))->count()),
            ];
        }

        return response()->json([
            'user_id'                  => $user->id,
            'role'                     => $user->role,
            'subscription_tier'        => $user->subscription_tier,
            'is_subscribed'            => $user->is_subscribed,
            'can_scan'                 => $user->can_scan,
            'free_limit'               => $freeLimit,
            'lifetime_free_scans_used' => $freeScansUsed,
            'scans_used'               => $freeScansUsed,
            'total_scans_count'        => $scansUsed,
            'remaining_scans'          => $user->remaining_scans, // -1 means unlimited
            'reward_points'            => (int) $user->fresh()->reward_points,
            'reward_settings'          => $this->rewardService->getSettings(),
            'active_subscription'      => $activeSub ? [
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
            'mycodex' => $mycodex,
        ]);
    }

    /**
     * POST /api/subscription/checkout
     * Initiates PayMongo checkout session for selected plan
     */
    public function checkout(Request $request)
    {
        $data = $request->validate([
            'plan'           => 'required|string|in:monthly,annual,pro_pass',
            'payment_method' => 'nullable|string|in:gcash',
            'points_to_use'  => 'nullable|integer|min:0',
            'success_url'    => 'nullable|string',
            'cancel_url'     => 'nullable|string',
        ]);

        $user = $request->user();
        $planKey = $data['plan'];

        if ($planKey === 'pro_pass') {
            try {
                $result = $this->rewardService->redeemProPass($user, 7);
                return response()->json([
                    'redeemed_full' => true,
                    'message'       => $result['message'],
                    'subscription'  => $result['subscription'],
                    'user'          => $user->fresh(),
                    'discount'      => ['points_used' => 35, 'is_full_redemption' => true],
                ]);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }
        $planDetails = $this->payMongoService->getPlan($planKey);

        if (!$planDetails) {
            return response()->json(['message' => 'Invalid subscription plan selected.'], 422);
        }

        $requestedPoints = (int) ($data['points_to_use'] ?? 0);
        $discount = $this->rewardService->calculateDiscount($user, $planDetails['amount'], $requestedPoints);

        // Full redemption using reward points (no PayMongo checkout needed)
        if ($discount['is_full_redemption']) {
            return DB::transaction(function () use ($user, $planKey, $planDetails, $discount, $request) {
                $lockedUser = User::whereKey($user->id)->lockForUpdate()->first();
                if (!$lockedUser || (int) $lockedUser->reward_points < $discount['points_used']) {
                    return response()->json(['message' => 'Insufficient reward points to complete redemption.'], 422);
                }

                $currentActive = Subscription::where('user_id', $lockedUser->id)
                    ->where('status', 'active')
                    ->where('expires_at', '>', now())
                    ->latest('expires_at')
                    ->first();

                $startsAt = Carbon::now();
                if ($currentActive && $currentActive->expires_at && $currentActive->expires_at->isFuture()) {
                    $baseExpiry = $currentActive->expires_at->copy();
                    $expiresAt = $planKey === 'annual' ? $baseExpiry->addYear() : $baseExpiry->addMonth();
                    $currentActive->update([
                        'status'       => 'cancelled',
                        'cancelled_at' => now(),
                        'auto_renew'   => false,
                        'metadata'     => array_merge($currentActive->metadata ?? [], [
                            'stacked_into_new_period' => true,
                        ]),
                    ]);
                } else {
                    $expiresAt = $planKey === 'annual' ? Carbon::now()->addYear() : Carbon::now()->addMonth();
                }

                $subscription = Subscription::create([
                    'user_id'             => $lockedUser->id,
                    'plan'                => $planKey,
                    'status'              => 'active',
                    'price'               => 0,
                    'currency'            => $planDetails['currency'],
                    'payment_method'      => 'reward_points',
                    'payment_status'      => 'paid',
                    'auto_renew'          => false,
                    'paymongo_session_id' => 'pts_' . bin2hex(random_bytes(8)),
                    'starts_at'           => $startsAt,
                    'expires_at'          => $expiresAt,
                    'metadata'            => [
                        'mode'           => 'reward_points',
                        'points_used'    => $discount['points_used'],
                        'discount_php'   => $discount['discount_php'],
                        'original_price' => $planDetails['amount'],
                        'ip_address'     => $request->ip(),
                        'stacked'        => (bool) $currentActive,
                    ],
                ]);

                $this->rewardService->redeemPoints(
                    $lockedUser,
                    $discount['points_used'],
                    'subscription_redemption',
                    "Redeemed {$discount['points_used']} pts for {$planDetails['name']}",
                    'subscription',
                    $subscription->id
                );

                SubscriptionEvent::log($lockedUser->id, 'activated', $subscription->id, null, [
                    'plan'           => $subscription->plan,
                    'amount'         => 0,
                    'payment_method' => 'reward_points',
                    'points_used'    => $discount['points_used'],
                    'source'         => 'checkout_full_points_redemption',
                ]);

                $this->transactionEmails->sendPaymentReceipt($subscription);

                return response()->json([
                    'redeemed_full' => true,
                    'message'       => "Your {$planDetails['name']} has been activated using {$discount['points_used']} reward points!",
                    'subscription'  => $subscription,
                    'user'          => $lockedUser->fresh(),
                    'discount'      => $discount,
                ]);
            });
        }

        try {
            $session = $this->payMongoService->createCheckoutSession(
                $user,
                $planKey,
                $data['success_url'] ?? null,
                $data['cancel_url'] ?? null,
                $data['payment_method'] ?? 'all',
                $discount['final_centavos'],
                $discount['discount_php']
            );

            // Record pending subscription in DB
            Subscription::create([
                'user_id'             => $user->id,
                'plan'                => $planKey,
                'status'              => 'pending',
                'price'               => $discount['final_amount_php'],
                'currency'            => $planDetails['currency'],
                'payment_method'      => $session['payment_method'] ?? ($data['payment_method'] ?? 'gcash'),
                'paymongo_session_id' => $session['session_id'],
                'metadata'            => [
                    'mode'           => $session['mode'] ?? 'standard',
                    'points_used'    => $discount['points_used'],
                    'discount_php'   => $discount['discount_php'],
                    'original_price' => $planDetails['amount'],
                    'ip_address'     => $request->ip(),
                ],
            ]);

            return response()->json([
                'redeemed_full' => false,
                'checkout_url'  => $session['checkout_url'],
                'session_id'    => $session['session_id'],
                'plan'          => $planDetails,
                'discount'      => $discount,
                'mode'          => $session['mode'] ?? 'standard',
            ]);
        } catch (\Exception $e) {
            Log::error('Checkout initiation failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'Unable to create checkout session. ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/subscription/redeem-pass
     * Redeems 35 reward points for a 7-day Pro pass.
     */
    public function redeemPass(Request $request)
    {
        $user = $request->user();
        try {
            $result = $this->rewardService->redeemProPass($user, 7);
            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to redeem pass: ' . $e->getMessage()], 500);
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

            [$subscription, $justActivated] = $this->activatePaidSubscription($subscription, $user->id, array_merge($sessionData, [
                'session_id' => $sessionId,
                'plan'       => $data['plan'] ?? null,
                'source'     => 'verify',
            ]));

            $emailSent = false;
            $emailWarning = null;
            if ($justActivated) {
                $emailSent = $this->handlePostActivation($subscription);
                $emailWarning = $this->transactionEmails->lastWarning();
            }

            return response()->json([
                'message'       => 'Your subscription has been activated successfully! Unlimited mushroom scans unlocked.',
                'subscription'  => $subscription,
                'user'          => $user->fresh(),
                'email_sent'    => $emailSent,
                'email_warning' => $emailWarning,
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
            if (!app()->environment('testing', 'local')) {
                Log::error('PayMongo webhook rejected: PAYMONGO_WEBHOOK_SECRET is not configured in production.');
                return response()->json(['message' => 'Webhook secret is not configured.'], 500);
            }
            Log::warning('PAYMONGO_WEBHOOK_SECRET is empty — allowed in local/testing only.');
        }

        $payload = $request->all();
        $event = $payload['data']['attributes']['type'] ?? null;

        Log::info('PayMongo Webhook event received: ' . $event, ['payload' => $payload]);

        if ($event === 'checkout_session.payment.paid') {
            try {
                $checkoutSession = $payload['data']['attributes']['data'] ?? [];
                $sessionId = $checkoutSession['id'] ?? null;
                $payments = $checkoutSession['attributes']['payments'] ?? [];
                $primaryPayment = $payments[0]['attributes'] ?? [];

                if ($sessionId) {
                    $sub = Subscription::where('paymongo_session_id', $sessionId)->first();

                    // Idempotent: PayMongo retries deliveries, so never re-activate an active subscription
                    if ($sub && $sub->status !== 'active') {
                        [$activated, $justActivated] = $this->activatePaidSubscription($sub, $sub->user_id, [
                            'session_id'     => $sessionId,
                            'payment_id'     => $payments[0]['id'] ?? null,
                            'payment_method' => $primaryPayment['source']['type'] ?? ($primaryPayment['payment_method_type'] ?? 'gcash'),
                            'amount'         => isset($primaryPayment['amount']) ? ($primaryPayment['amount'] / 100) : 0,
                            'currency'       => $primaryPayment['currency'] ?? 'PHP',
                            'source'         => 'webhook',
                        ]);

                        if ($justActivated) {
                            $this->handlePostActivation($activated);
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Payment webhook handling failed: ' . $e->getMessage());
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

        $failed    = $type === 'refund' && in_array($status, ['failed', 'rejected'], true);
        $succeeded = $event === 'payment.refunded' || $status === 'succeeded';

        if ($failed) {
            RefundController::markFailed(
                $refund,
                'PayMongo reported the refund as ' . ($status ?: 'failed') . '.',
                $refund->reviewed_by
            );
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
     * Handle actions right after a subscription is marked active for the first time:
     * 1. Deduct any used reward points.
     * 2. Send transaction payment receipt email.
     */
    private function handlePostActivation(Subscription $sub): bool
    {
        $pointsUsed = (int) ($sub->metadata['points_used'] ?? 0);
        if ($pointsUsed > 0 && $sub->user_id) {
            $subUser = User::find($sub->user_id);
            if ($subUser) {
                $this->rewardService->redeemPoints(
                    $subUser,
                    $pointsUsed,
                    'subscription_discount',
                    "Applied {$pointsUsed} pts discount on {$sub->plan} subscription",
                    'subscription',
                    $sub->id
                );
            }
        }

        return $this->transactionEmails->sendPaymentReceipt($sub);
    }

    /**
     * Atomically mark a paid PayMongo checkout subscription as active once.
     * Stacks duration if user already has an active subscription.
     * Returns [$subscription, $justActivated] so payment receipts are sent exactly once.
     *
     * @return array{0: Subscription, 1: bool}
     */
    private function activatePaidSubscription(?Subscription $existing, ?int $userId, array $paymentData): array
    {
        return DB::transaction(function () use ($existing, $userId, $paymentData) {
            $sessionId = $paymentData['session_id'] ?? $existing?->paymongo_session_id;

            $locked = null;
            if ($existing) {
                $locked = Subscription::whereKey($existing->id)->lockForUpdate()->first();
            } elseif ($sessionId) {
                $locked = Subscription::where('paymongo_session_id', $sessionId)
                    ->when($userId, fn ($q) => $q->where('user_id', $userId))
                    ->lockForUpdate()
                    ->first();
            }

            if ($locked && $locked->status === 'active') {
                return [$locked, false];
            }

            $planKey = $paymentData['plan'] ?? ($locked?->plan ?? 'monthly');
            $planDetails = $this->payMongoService->getPlan($planKey) ?? $this->payMongoService->getPlan('monthly');

            $resolvedAmount = (float) ($paymentData['amount'] ?? 0);
            if (($paymentData['mode'] ?? '') === 'sandbox_simulator' && $locked && $locked->price > 0) {
                $resolvedAmount = (float) $locked->price;
            } elseif ($resolvedAmount <= 0) {
                $resolvedAmount = ($locked && $locked->price > 0)
                    ? (float) $locked->price
                    : (float) $planDetails['amount'];
            }

            // Stack on top of existing active subscription if one exists
            $currentActive = Subscription::where('user_id', $userId)
                ->where('status', 'active')
                ->where('expires_at', '>', now())
                ->when($locked?->id, fn ($q) => $q->where('id', '!=', $locked->id))
                ->latest('expires_at')
                ->first();

            $startsAt = Carbon::now();

            if ($currentActive && $currentActive->expires_at && $currentActive->expires_at->isFuture()) {
                $baseExpiry = $currentActive->expires_at->copy();
                $expiresAt = match ($planKey) {
                    'annual'   => $baseExpiry->addYear(),
                    'pro_pass' => $baseExpiry->addDays(7),
                    default    => $baseExpiry->addMonth(),
                };

                // Close superseded subscription so only one active subscription remains
                $currentActive->update([
                    'status'       => 'cancelled',
                    'cancelled_at' => now(),
                    'auto_renew'   => false,
                    'metadata'     => array_merge($currentActive->metadata ?? [], [
                        'superseded_by_subscription_id' => $locked?->id,
                        'stacked_into_new_period'       => true,
                    ]),
                ]);
            } else {
                $expiresAt = match ($planKey) {
                    'annual'   => $startsAt->copy()->addYear(),
                    'pro_pass' => $startsAt->copy()->addDays(7),
                    default    => $startsAt->copy()->addMonth(),
                };
            }

            $metadata = $locked?->metadata ?? [];
            $metadata['verified_at'] = Carbon::now()->toIso8601String();
            if ($currentActive) {
                $metadata['stacked_from_subscription_id'] = $currentActive->id;
            }
            if (($paymentData['source'] ?? null) === 'status_auto_sync') {
                $metadata['auto_synced'] = true;
            }
            if (($paymentData['source'] ?? null) !== 'webhook') {
                $metadata['session'] = $paymentData;
            }

            $attributes = [
                'plan'                => $planKey,
                'status'              => 'active',
                'payment_status'      => 'paid',
                'price'               => $resolvedAmount,
                'currency'            => $paymentData['currency'] ?? ($locked?->currency ?? 'PHP'),
                'paymongo_payment_id' => $paymentData['payment_id'] ?? $locked?->paymongo_payment_id,
                'payment_method'      => $paymentData['payment_method'] ?? ($locked?->payment_method ?? 'paymongo'),
                'starts_at'           => $startsAt,
                'expires_at'          => $expiresAt,
                'metadata'            => $metadata,
            ];

            if ($locked) {
                $locked->update($attributes);
                $sub = $locked->fresh();
            } else {
                $sub = Subscription::create(array_merge($attributes, [
                    'user_id'             => $userId,
                    'paymongo_session_id' => $sessionId,
                ]));
            }

            SubscriptionEvent::log($sub->user_id, 'activated', $sub->id, null, [
                'plan'           => $sub->plan,
                'amount'         => $sub->price,
                'payment_id'     => $sub->paymongo_payment_id,
                'payment_method' => $sub->payment_method,
                'source'         => $paymentData['source'] ?? 'verify',
                'stacked'        => (bool) $currentActive,
            ]);

            return [$sub, true];
        });
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
