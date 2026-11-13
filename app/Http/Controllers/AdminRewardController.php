<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\PointTransaction;
use App\Models\User;
use App\Services\RewardService;
use Illuminate\Http\Request;

class AdminRewardController extends Controller
{
    public function __construct(protected RewardService $rewardService)
    {
    }

    /**
     * GET /api/admin/rewards
     * Returns reward settings, economy stats, and recent point transactions.
     */
    public function index()
    {
        $settings = $this->rewardService->getSettings();

        $totalCirculating = (int) User::sum('reward_points');
        $totalEarned = (int) PointTransaction::where('points', '>', 0)->sum('points');
        $totalRedeemed = (int) abs((int) PointTransaction::where('points', '<', 0)->sum('points'));
        $usersWithPoints = (int) User::where('reward_points', '>', 0)->count();

        $transactions = PointTransaction::with('user:id,name,email,reward_points')
            ->latest('id')
            ->take(40)
            ->get();

        return response()->json([
            'settings' => $settings,
            'stats' => [
                'total_circulating' => $totalCirculating,
                'total_earned'      => $totalEarned,
                'total_redeemed'    => $totalRedeemed,
                'users_with_points' => $usersWithPoints,
            ],
            'transactions' => $transactions,
        ]);
    }

    /**
     * PUT /api/admin/rewards/settings
     * Updates the reward system configuration.
     */
    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'enabled'               => 'required|boolean',
            'points_per_scan'       => 'required|integer|min:0|max:500',
            'points_location_bonus' => 'required|integer|min:0|max:500',
            'points_retrain_bonus'  => 'required|integer|min:0|max:1000',
            'daily_scan_cap'        => 'required|integer|min:0|max:100',
            'min_redeem_points'     => 'required|integer|min:1|max:5000',
            'php_per_point'         => 'required|numeric|min:0.1|max:100',
            'allow_full_redemption' => 'required|boolean',
            'max_discount_percent'  => 'required|integer|min:5|max:100',
        ]);

        AppSetting::setValue('reward_settings', $data);

        return response()->json([
            'message'  => 'Reward system settings updated.',
            'settings' => $this->rewardService->getSettings(),
        ]);
    }

    /**
     * POST /api/admin/rewards/adjust
     * Manually grants or deducts points for a user by email or user_id.
     */
    public function adjust(Request $request)
    {
        $data = $request->validate([
            'user_identifier' => 'required|string',
            'points'          => 'required|integer|not_in:0|between:-10000,10000',
            'reason'          => 'required|string|max:255',
        ]);

        $identifier = trim($data['user_identifier']);
        $user = is_numeric($identifier)
            ? User::find((int) $identifier)
            : User::where('email', $identifier)->orWhere('username', $identifier)->first();

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $tx = $this->rewardService->adjustPoints(
            $user,
            (int) $data['points'],
            'Admin adjustment: ' . trim($data['reason'])
        );

        return response()->json([
            'message'     => "Adjusted {$user->email} by {$tx->points} pts.",
            'transaction' => $tx->load('user:id,name,email,reward_points'),
            'new_balance' => $user->fresh()->reward_points,
        ]);
    }
}
