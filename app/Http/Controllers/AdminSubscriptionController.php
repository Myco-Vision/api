<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\User;
use App\Services\PlanConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin-side management of user subscriptions:
 * listing/filtering, stats, manual grants, extensions, cancellations,
 * revocations, auto-renew toggling and the audit trail.
 */
class AdminSubscriptionController extends Controller
{
    // GET /api/admin/subscriptions
    public function index(Request $request)
    {
        $status = $request->input('status'); // active | expiring | expired | cancelled | pending
        $now    = now();

        $subs = Subscription::with('user:id,name,email,username,role')
            ->when($request->search, function ($q, $search) {
                $q->where(function ($w) use ($search) {
                    $w->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%"))
                      ->orWhere('paymongo_payment_id', 'like', "%{$search}%");
                });
            })
            ->when($request->plan, fn ($q, $plan) => $q->where('plan', $plan))
            ->when($status === 'active', fn ($q) => $q->where('status', 'active')->where('expires_at', '>', $now))
            ->when($status === 'expiring', fn ($q) => $q->where('status', 'active')->whereBetween('expires_at', [$now, $now->copy()->addDays(7)]))
            ->when($status === 'expired', fn ($q) => $q->where(fn ($w) => $w->where('status', 'expired')
                ->orWhere(fn ($x) => $x->where('status', 'active')->where('expires_at', '<=', $now))))
            ->when(in_array($status, ['cancelled', 'pending'], true), fn ($q) => $q->where('status', $status))
            ->latest('id')
            ->paginate((int) $request->input('per_page', 15));

        $subs->getCollection()->transform(fn ($s) => $this->present($s));

        return response()->json($subs);
    }

    // GET /api/admin/subscriptions/stats
    public function stats()
    {
        $now    = now();
        $active = Subscription::where('status', 'active')->where('expires_at', '>', $now);
        $plans  = PlanConfigService::paidPlans();

        $monthly = (clone $active)->where('plan', 'monthly')->count();
        $annual  = (clone $active)->where('plan', 'annual')->count();

        // Monthly recurring revenue, normalising annual plans to a per-month figure
        $mrr = $monthly * ($plans['monthly']['amount'] ?? 0)
             + $annual * (($plans['annual']['amount'] ?? 0) / 12);

        $paid = Subscription::whereNotNull('paymongo_payment_id')
            ->where(fn ($q) => $q->whereNull('payment_status')->orWhere('payment_status', 'paid'));

        return response()->json([
            'active'          => $monthly + $annual,
            'monthly'         => $monthly,
            'annual'          => $annual,
            'expiring_soon'   => (clone $active)->where('expires_at', '<=', $now->copy()->addDays(7))->count(),
            'cancelled'       => Subscription::where('status', 'cancelled')->count(),
            'pending'         => Subscription::where('status', 'pending')->count(),
            'mrr'             => round($mrr, 2),
            'total_revenue'   => round((float) (clone $paid)->sum('price'), 2),
            'revenue_30d'     => round((float) (clone $paid)->where('starts_at', '>=', $now->copy()->subDays(30))->sum('price'), 2),
            'free_users'      => User::where('role', 'forager')
                ->whereDoesntHave('subscriptions', fn ($q) => $q->where('status', 'active')->where('expires_at', '>', $now))
                ->count(),
            'plans'           => $plans,
        ]);
    }

    // GET /api/admin/subscriptions/events
    public function events(Request $request)
    {
        $events = SubscriptionEvent::with(['user:id,name,email', 'admin:id,name'])
            ->when($request->user_id, fn ($q, $id) => $q->where('user_id', $id))
            ->latest('id')
            ->paginate((int) $request->input('per_page', 20));

        return response()->json($events);
    }

    // GET /api/admin/subscriptions/user/{user}
    public function userHistory(User $user)
    {
        return response()->json([
            'user'          => $user->only(['id', 'name', 'email', 'username', 'role', 'created_at']),
            'tier'          => $user->subscription_tier,
            'subscriptions' => $user->subscriptions()->latest('id')->get()->map(fn ($s) => $this->present($s)),
            'events'        => SubscriptionEvent::with('admin:id,name')->where('user_id', $user->id)->latest('id')->limit(50)->get(),
        ]);
    }

    // POST /api/admin/subscriptions/grant
    public function grant(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'plan'    => 'required|in:monthly,annual',
            'days'    => 'nullable|integer|min:1|max:3650',
            'note'    => 'nullable|string|max:500',
        ]);

        $user = User::findOrFail($data['user_id']);
        if ($user->isAdmin()) {
            return response()->json(['message' => 'Admins already have full access.'], 422);
        }

        $days = $data['days'] ?? ($data['plan'] === 'annual' ? 365 : 30);

        $sub = DB::transaction(function () use ($user, $data, $days, $request) {
            // Stack on top of any current active subscription instead of overlapping it
            $current = $user->activeSubscription;
            $start   = now();
            if ($current) {
                $current->update(['status' => 'cancelled', 'cancelled_at' => now(), 'auto_renew' => false]);
                $start = $current->expires_at;
            }

            $sub = Subscription::create([
                'user_id'        => $user->id,
                'plan'           => $data['plan'],
                'status'         => 'active',
                'price'          => 0,
                'currency'       => 'PHP',
                'payment_method' => 'admin_grant',
                'payment_status' => 'complimentary',
                'auto_renew'     => false,
                'starts_at'      => now(),
                'expires_at'     => $start->copy()->addDays($days),
                'metadata'       => ['granted_by' => $request->user()->id, 'note' => $data['note'] ?? null],
            ]);

            SubscriptionEvent::log($user->id, 'granted', $sub->id, $request->user()->id, [
                'plan' => $data['plan'], 'days' => $days, 'note' => $data['note'] ?? null,
                'replaced_subscription_id' => $current?->id,
            ]);

            return $sub;
        });

        return response()->json(['message' => 'Subscription granted.', 'subscription' => $this->present($sub->load('user'))], 201);
    }

    // POST /api/admin/subscriptions/{subscription}/extend
    public function extend(Request $request, Subscription $subscription)
    {
        $data = $request->validate([
            'days' => 'required|integer|min:1|max:3650',
            'note' => 'nullable|string|max:500',
        ]);

        $base = ($subscription->expires_at && $subscription->expires_at->isFuture()) ? $subscription->expires_at : now();
        $old  = $subscription->expires_at;

        $subscription->update([
            'status'       => 'active',
            'cancelled_at' => null,
            'expires_at'   => $base->copy()->addDays($data['days']),
        ]);

        SubscriptionEvent::log($subscription->user_id, 'extended', $subscription->id, $request->user()->id, [
            'days' => $data['days'], 'from' => $old?->toIso8601String(),
            'to' => $subscription->expires_at->toIso8601String(), 'note' => $data['note'] ?? null,
        ]);

        return response()->json(['message' => 'Subscription extended.', 'subscription' => $this->present($subscription->load('user'))]);
    }

    // POST /api/admin/subscriptions/{subscription}/cancel
    // Stops renewal; the user keeps access until the current period ends.
    public function cancel(Request $request, Subscription $subscription)
    {
        $request->validate(['note' => 'nullable|string|max:500']);

        $subscription->update(['auto_renew' => false, 'cancelled_at' => now()]);

        SubscriptionEvent::log($subscription->user_id, 'cancelled', $subscription->id, $request->user()->id, [
            'access_until' => $subscription->expires_at?->toIso8601String(), 'note' => $request->note,
        ]);

        return response()->json(['message' => 'Subscription will not renew. Access remains until expiry.', 'subscription' => $this->present($subscription->load('user'))]);
    }

    // POST /api/admin/subscriptions/{subscription}/revoke
    // Immediately ends access.
    public function revoke(Request $request, Subscription $subscription)
    {
        $request->validate(['note' => 'nullable|string|max:500']);

        $subscription->update([
            'status'       => 'cancelled',
            'auto_renew'   => false,
            'cancelled_at' => now(),
            'expires_at'   => now(),
        ]);

        SubscriptionEvent::log($subscription->user_id, 'revoked', $subscription->id, $request->user()->id, [
            'note' => $request->note,
        ]);

        return response()->json(['message' => 'Subscription revoked.', 'subscription' => $this->present($subscription->load('user'))]);
    }

    // PATCH /api/admin/subscriptions/{subscription}/auto-renew
    public function toggleAutoRenew(Request $request, Subscription $subscription)
    {
        $data = $request->validate(['auto_renew' => 'required|boolean']);

        $subscription->update(['auto_renew' => $data['auto_renew']] + ($data['auto_renew'] ? ['cancelled_at' => null] : []));

        SubscriptionEvent::log($subscription->user_id, $data['auto_renew'] ? 'auto_renew_on' : 'auto_renew_off', $subscription->id, $request->user()->id);

        return response()->json(['message' => 'Auto-renew updated.', 'subscription' => $this->present($subscription->load('user'))]);
    }

    /** Normalised shape for the admin UI, including the derived display state. */
    private function present(Subscription $s): array
    {
        $state = $s->status;
        if ($s->status === 'active' && $s->expires_at && $s->expires_at->isPast()) {
            $state = 'expired';
        } elseif ($s->status === 'active' && $s->cancelled_at) {
            $state = 'cancelling';
        } elseif ($s->status === 'active' && $s->expires_at && $s->expires_at->lte(now()->addDays(7))) {
            $state = 'expiring';
        }

        return array_merge($s->toArray(), [
            'state'          => $state,
            'days_remaining' => $s->expires_at && $s->expires_at->isFuture() ? (int) ceil(now()->diffInHours($s->expires_at) / 24) : 0,
            'auto_renew'     => (bool) ($s->auto_renew ?? false),
        ]);
    }
}
