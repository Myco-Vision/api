<?php

namespace App\Http\Controllers;

use App\Services\RewardService;
use Illuminate\Http\Request;

class RewardController extends Controller
{
    public function __construct(protected RewardService $rewardService)
    {
    }

    /**
     * GET /api/rewards
     * Returns current user's reward point balance, rules, daily cap status, and transaction history.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $settings = $this->rewardService->getSettings();
        $phpPerPoint = (float) ($settings['php_per_point'] ?? 1.0);

        $transactions = $user->pointTransactions()
            ->latest('id')
            ->take(30)
            ->get();

        return response()->json([
            'enabled'             => (bool) ($settings['enabled'] ?? true),
            'reward_points'       => (int) $user->reward_points,
            'points_value_php'    => round((int) $user->reward_points * $phpPerPoint, 2),
            'allow_data_training' => (bool) $user->allow_data_training,
            'daily_scan_count'    => $this->rewardService->getDailyRewardedScanCount($user),
            'daily_scan_cap'      => (int) ($settings['daily_scan_cap'] ?? RewardService::DAILY_SCAN_REWARD_CAP),
            'rules' => [
                'scan_contribution'     => (int) ($settings['points_per_scan'] ?? RewardService::POINTS_PER_SCAN),
                'location_bonus'        => (int) ($settings['points_location_bonus'] ?? RewardService::POINTS_LOCATION_BONUS),
                'retrain_bonus'         => (int) ($settings['points_retrain_bonus'] ?? RewardService::POINTS_RETRAIN_BONUS),
                'min_redeem_points'     => (int) ($settings['min_redeem_points'] ?? RewardService::MIN_REDEEM_POINTS),
                'php_per_point'         => $phpPerPoint,
                'allow_full_redemption' => (bool) ($settings['allow_full_redemption'] ?? true),
                'max_discount_percent'  => (int) ($settings['max_discount_percent'] ?? 100),
            ],
            'transactions' => $transactions,
        ]);
    }
}
