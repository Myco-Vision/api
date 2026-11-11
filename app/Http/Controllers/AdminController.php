<?php

namespace App\Http\Controllers;

use App\Models\RefundRequest;
use App\Models\Scan;
use App\Models\Species;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AdminController extends Controller
{
    // GET /api/admin/dashboard  — key stats + recent scans + chart data
    public function dashboard()
    {
        $totalUsers  = User::where('role', 'forager')->count();
        $totalAdmins = User::where('role', 'admin')->count();
        $totalScans  = Scan::count();
        $scansToday  = Scan::whereDate('created_at', today())->count();
        $edibleScans = Scan::where('result_classification', 'edible')->count();
        $poisonousScans = Scan::where('result_classification', 'poisonous')->count();
        $unknownScans = $totalScans - $edibleScans - $poisonousScans;
        $speciesCount = Species::count();

        // Average confidence
        $avgConfidence = Scan::avg('confidence_level') ?? 0;

        // New users this month vs last month for % change
        $usersThisMonth = User::where('role', 'forager')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
        $usersLastMonth = User::where('role', 'forager')
            ->whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->count();

        // Scans today vs yesterday
        $scansYesterday = Scan::whereDate('created_at', today()->subDay())->count();

        // Scan volume last 7 days
        $scanVolume = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $scanVolume[] = [
                'date'  => $date->format('D'),
                'count' => Scan::whereDate('created_at', $date->toDateString())->count(),
            ];
        }

        // Classification percentages
        $ediblePct   = $totalScans > 0 ? round(($edibleScans / $totalScans) * 100) : 0;
        $poisonousPct = $totalScans > 0 ? round(($poisonousScans / $totalScans) * 100) : 0;
        $unknownPct  = $totalScans > 0 ? (100 - $ediblePct - $poisonousPct) : 0;

        // Recent scans (last 5)
        $recentScans = Scan::with('user', 'species')
            ->latest()
            ->take(5)
            ->get()
            ->map(fn ($scan) => [
                'id'         => $scan->id,
                'user'       => $scan->user?->name ?? 'Unknown',
                'species'    => $scan->species?->name ?? $scan->result_name ?? 'Unknown',
                'cls'        => $scan->result_classification ?? 'unknown',
                'confidence' => round($scan->confidence_level ?? 0),
                'time'       => $scan->created_at->diffForHumans(),
            ]);

        return response()->json([
            'total_users'        => $totalUsers,
            'total_admins'       => $totalAdmins,
            'total_scans'        => $totalScans,
            'scans_today'        => $scansToday,
            'scans_yesterday'    => $scansYesterday,
            'edible_scans'       => $edibleScans,
            'poisonous_scans'    => $poisonousScans,
            'unknown_scans'      => $unknownScans,
            'species_count'      => $speciesCount,
            'avg_confidence'     => round($avgConfidence, 1),
            'users_this_month'   => $usersThisMonth,
            'users_last_month'   => $usersLastMonth,
            'scan_volume'        => $scanVolume,
            'edible_pct'         => $ediblePct,
            'poisonous_pct'      => $poisonousPct,
            'unknown_pct'        => $unknownPct,
            'recent_scans'       => $recentScans,
            'active_subscribers' => \App\Models\Subscription::where('status', 'active')->where('expires_at', '>', now())->count(),
            'total_revenue'      => (float) \App\Models\Subscription::where('status', 'active')->sum('price'),
        ]);
    }

    // GET /api/admin/users
    public function users(Request $request)
    {
        $users = User::withCount('scans')
            ->with('activeSubscription')
            ->when($request->search, fn ($q) =>
                $q->where(fn ($q2) => $q2
                    ->where('name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%")
                    ->orWhere('username', 'like', "%{$request->search}%"))
            )
            ->when($request->role, fn ($q) =>
                $q->where('role', $request->role)
            )
            ->latest()
            ->paginate($request->per_page ?? 20);

        return response()->json($users);
    }

    // GET /api/admin/users/{user}
    public function showUser(User $user)
    {
        return response()->json($user->loadCount('scans')->load('scans'));
    }

    // DELETE /api/admin/users/{user}
    public function destroyUser(User $user)
    {
        if ($user->isSuperAdmin()) {
            return response()->json(['message' => 'Cannot delete a Super Admin account.'], 403);
        }
        if ($user->isAdmin()) {
            return response()->json(['message' => 'Cannot delete an admin account. Use Account Management.'], 403);
        }
        $user->delete();

        return response()->json(['message' => 'User deleted.']);
    }

    // GET /api/admin/scans
    public function scans(Request $request)
    {
        $scans = Scan::with('user', 'species')
            ->when($request->user_id, fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->has('only_consented') && ($request->boolean('only_consented') || $request->only_consented == '1'), function ($q) {
                $q->whereHas('user', function ($u) {
                    $u->where('allow_data_training', 1)->orWhere('allow_data_training', true);
                });
            })
            ->when($request->search, fn ($q) =>
                $q->where(fn ($q2) => $q2
                    ->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$request->search}%"))
                    ->orWhere('result_name', 'like', "%{$request->search}%"))
            )
            ->when($request->classification, fn ($q) =>
                $q->where('result_classification', $request->classification)
            )
            ->when($request->date_filter, function ($q) use ($request) {
                switch ($request->date_filter) {
                    case 'today':
                        $q->whereDate('created_at', today());
                        break;
                    case 'week':
                        $q->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]);
                        break;
                    case 'month':
                        $q->whereMonth('created_at', now()->month)
                          ->whereYear('created_at', now()->year);
                        break;
                }
            })
            ->latest()
            ->paginate($request->per_page ?? 20);

        return response()->json($scans);
    }

    // GET /api/admin/scans/candidates — Privacy Filtered Scans for AI Model Training
    public function candidateScans(Request $request)
    {
        // STRICT PRIVACY FILTER: Only include scans from users with allow_data_training = true
        $candidates = Scan::with('user', 'species')
            ->whereHas('user', function ($q) {
                $q->where('allow_data_training', true);
            })
            ->latest()
            ->paginate(20);

        return response()->json($candidates);
    }

    // GET /api/admin/reports — analytics data
    public function reports(Request $request)
    {
        $days = (int) ($request->period ?? 30);
        $startDate = now()->subDays($days);

        $totalScans = Scan::where('created_at', '>=', $startDate)->count();
        $avgConfidence = Scan::where('created_at', '>=', $startDate)->avg('confidence_level') ?? 0;

        $activeUsers = User::where('role', 'forager')
            ->whereHas('scans', fn ($q) => $q->where('created_at', '>=', $startDate))
            ->count();

        $speciesCount = Species::count();

        // Edible / Poisonous / Unknown counts for the period
        $edibleCount = Scan::where('created_at', '>=', $startDate)
            ->where('result_classification', 'edible')->count();
        $poisonousCount = Scan::where('created_at', '>=', $startDate)
            ->where('result_classification', 'poisonous')->count();
        $unknownCount = $totalScans - $edibleCount - $poisonousCount;

        $ediblePct   = $totalScans > 0 ? round(($edibleCount / $totalScans) * 100) : 0;
        $poisonousPct = $totalScans > 0 ? round(($poisonousCount / $totalScans) * 100) : 0;
        $unknownPct  = $totalScans > 0 ? (100 - $ediblePct - $poisonousPct) : 0;

        // Scan volume over time (up to 7 data points)
        $interval = max(1, intdiv($days, 7));
        $scanVolume = [];
        for ($i = 6; $i >= 0; $i--) {
            $from = now()->subDays($i * $interval + $interval - 1)->startOfDay();
            $to   = now()->subDays($i * $interval)->endOfDay();
            if ($from < $startDate) $from = $startDate;
            $scanVolume[] = [
                'label' => $from->format('M d'),
                'count' => Scan::whereBetween('created_at', [$from, $to])->count(),
            ];
        }

        // Top 5 identified species
        $topSpecies = Scan::select('result_name', DB::raw('COUNT(*) as count'))
            ->where('created_at', '>=', $startDate)
            ->whereNotNull('result_name')
            ->groupBy('result_name')
            ->orderByDesc('count')
            ->take(5)
            ->get();

        // Confidence distribution
        $conf90 = Scan::where('created_at', '>=', $startDate)
            ->where('confidence_level', '>=', 90)->count();
        $conf70 = Scan::where('created_at', '>=', $startDate)
            ->whereBetween('confidence_level', [70, 89.99])->count();
        $conf50 = Scan::where('created_at', '>=', $startDate)
            ->whereBetween('confidence_level', [50, 69.99])->count();
        $confLow = Scan::where('created_at', '>=', $startDate)
            ->where('confidence_level', '<', 50)->count();

        $confTotal = max(1, $conf90 + $conf70 + $conf50 + $confLow);

        // Top users by scan count
        $topUsers = User::withCount(['scans' => fn ($q) => $q->where('created_at', '>=', $startDate)])
            ->having('scans_count', '>', 0)
            ->orderByDesc('scans_count')
            ->take(5)
            ->get()
            ->map(fn ($u) => [
                'name'     => $u->name,
                'initials' => strtoupper(collect(explode(' ', $u->name))->map(fn ($w) => $w[0] ?? '')->join('')),
                'scans'    => $u->scans_count,
            ]);

        return response()->json([
            'total_scans'    => $totalScans,
            'avg_confidence' => round($avgConfidence, 1),
            'active_users'   => $activeUsers,
            'species_count'  => $speciesCount,
            'edible_pct'     => $ediblePct,
            'poisonous_pct'  => $poisonousPct,
            'unknown_pct'    => $unknownPct,
            'scan_volume'    => $scanVolume,
            'top_species'    => $topSpecies,
            'conf_buckets'   => [
                ['label' => '90-100%', 'pct' => round(($conf90 / $confTotal) * 100), 'color' => '#10b981'],
                ['label' => '70-89%',  'pct' => round(($conf70 / $confTotal) * 100), 'color' => '#3b82f6'],
                ['label' => '50-69%',  'pct' => round(($conf50 / $confTotal) * 100), 'color' => '#f59e0b'],
                ['label' => '<50%',    'pct' => round(($confLow / $confTotal) * 100), 'color' => '#ef4444'],
            ],
            'top_users'      => $topUsers,
        ]);
    }

    // GET /api/admin/notifications — lightweight actionable alert counts
    public function notifications(Request $request)
    {
        $isSuperAdmin = $request->user()?->role === 'super_admin';
        $items = [];

        $pendingRefunds = RefundRequest::whereIn('status', ['pending', 'processing'])->count();
        if ($pendingRefunds > 0) {
            $items[] = [
                'key'      => 'pending_refunds',
                'title'    => 'Pending Refund Requests',
                'subtitle' => $pendingRefunds === 1
                    ? '1 refund request awaiting review'
                    : "{$pendingRefunds} refund requests awaiting review",
                'count'    => $pendingRefunds,
                'tone'     => 'amber',
                'route'    => '/admin/subscriptions',
            ];
        }

        $poisonous24h = Scan::where('result_classification', 'poisonous')
            ->where('created_at', '>=', now()->subDay())
            ->count();
        if ($poisonous24h > 0) {
            $items[] = [
                'key'      => 'poisonous_scans',
                'title'    => 'Poisonous Scans (24h)',
                'subtitle' => $poisonous24h === 1
                    ? '1 poisonous specimen logged today'
                    : "{$poisonous24h} poisonous specimens logged today",
                'count'    => $poisonous24h,
                'tone'     => 'red',
                'route'    => '/admin/scans',
            ];
        }

        $unknown24h = Scan::where(function ($q) {
                $q->whereNull('result_classification')
                  ->orWhere('result_classification', 'unknown');
            })
            ->where('created_at', '>=', now()->subDay())
            ->count();
        if ($unknown24h > 0) {
            $items[] = [
                'key'      => 'unknown_scans',
                'title'    => 'Unclassified / Failed Scans (24h)',
                'subtitle' => $unknown24h === 1
                    ? '1 scan returned unknown or error'
                    : "{$unknown24h} scans returned unknown or error",
                'count'    => $unknown24h,
                'tone'     => 'slate',
                'route'    => '/admin/scans',
            ];
        }

        if ($isSuperAdmin) {
            $retrainCandidates = Scan::whereHas('user', fn ($q) => $q->where('allow_data_training', true))
                ->whereIn('result_classification', ['edible', 'poisonous'])
                ->whereNotNull('image_path')
                ->whereNull('retrained_at')
                ->count();
            if ($retrainCandidates > 0) {
                $items[] = [
                    'key'      => 'retrain_candidates',
                    'title'    => 'AI Retrain Candidates Ready',
                    'subtitle' => $retrainCandidates >= 20
                        ? "{$retrainCandidates} consented images ready (recommended 20+ met)"
                        : "{$retrainCandidates} consented images queued (20 recommended)",
                    'count'    => $retrainCandidates,
                    'tone'     => 'emerald',
                    'route'    => '/admin/species?tab=retrain',
                ];
            }

            $unlinkedSpecies = Scan::whereHas('user', fn ($q) => $q->where('allow_data_training', true))
                ->whereNull('species_id')
                ->whereIn('result_classification', ['edible', 'poisonous'])
                ->count();
            if ($unlinkedSpecies > 0) {
                $items[] = [
                    'key'      => 'unlinked_species',
                    'title'    => 'Candidate Species Not in DB',
                    'subtitle' => $unlinkedSpecies === 1
                        ? '1 consented scan ready for species catalog review'
                        : "{$unlinkedSpecies} consented scans ready for species catalog review",
                    'count'    => $unlinkedSpecies,
                    'tone'     => 'blue',
                    'route'    => '/admin/species?tab=candidates',
                ];
            }
        }

        return response()->json([
            'total_count' => count($items),
            'items'       => $items,
        ]);
    }
}