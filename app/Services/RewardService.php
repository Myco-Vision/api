<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\PointTransaction;
use App\Models\Scan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RewardService
{
    public const POINTS_PER_SCAN = 5;
    public const POINTS_LOCATION_BONUS = 3;
    public const POINTS_RETRAIN_BONUS = 15;
    public const DAILY_SCAN_REWARD_CAP = 5;
    public const MIN_PAYMONGO_AMOUNT_PHP = 20;
    public const MIN_REDEEM_POINTS = 10;

    public const DEFAULT_SETTINGS = [
        'enabled'               => true,
        'points_per_scan'       => self::POINTS_PER_SCAN,
        'points_location_bonus' => self::POINTS_LOCATION_BONUS,
        'points_retrain_bonus'  => self::POINTS_RETRAIN_BONUS,
        'daily_scan_cap'        => self::DAILY_SCAN_REWARD_CAP,
        'min_redeem_points'     => self::MIN_REDEEM_POINTS,
        'php_per_point'         => 1.0,
        'allow_full_redemption' => true,
        'max_discount_percent'  => 100,
    ];

    public function getSettings(): array
    {
        $saved = AppSetting::getValue('reward_settings', []);
        if (!is_array($saved)) {
            $saved = [];
        }

        return array_merge(self::DEFAULT_SETTINGS, $saved);
    }

    /**
     * Award points when a consented user submits a valid mushroom scan.
     */
    public function awardForScan(User $user, Scan $scan): array
    {
        $settings = $this->getSettings();

        if (!($settings['enabled'] ?? true)) {
            return ['points_earned' => 0, 'reason' => 'rewards_disabled'];
        }

        if (!$user->allow_data_training || $scan->result_classification === 'not_mushroom') {
            return ['points_earned' => 0, 'reason' => 'not_eligible'];
        }

        $dailyCap = max(0, (int) ($settings['daily_scan_cap'] ?? self::DAILY_SCAN_REWARD_CAP));
        $todayRewardedScans = PointTransaction::where('user_id', $user->id)
            ->where('type', 'scan_contribution')
            ->whereDate('created_at', now()->toDateString())
            ->count();

        if ($dailyCap > 0 && $todayRewardedScans >= $dailyCap) {
            return ['points_earned' => 0, 'reason' => 'daily_cap_reached'];
        }

        $ptsPerScan = max(0, (int) ($settings['points_per_scan'] ?? self::POINTS_PER_SCAN));
        $ptsLocation = max(0, (int) ($settings['points_location_bonus'] ?? self::POINTS_LOCATION_BONUS));

        if ($ptsPerScan === 0 && $ptsLocation === 0) {
            return ['points_earned' => 0, 'reason' => 'zero_points_configured'];
        }

        return DB::transaction(function () use ($user, $scan, $ptsPerScan, $ptsLocation) {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();
            $totalEarned = 0;

            if ($ptsPerScan > 0) {
                PointTransaction::create([
                    'user_id'        => $lockedUser->id,
                    'points'         => $ptsPerScan,
                    'type'           => 'scan_contribution',
                    'description'    => "Contributed scan ({$scan->result_name}) for AI improvement",
                    'reference_type' => 'scan',
                    'reference_id'   => $scan->id,
                ]);
                $totalEarned += $ptsPerScan;
            }

            $hasLocation = !is_null($scan->latitude) && !is_null($scan->longitude);
            if ($hasLocation && $ptsLocation > 0) {
                PointTransaction::create([
                    'user_id'        => $lockedUser->id,
                    'points'         => $ptsLocation,
                    'type'           => 'location_bonus',
                    'description'    => "Geotagged location bonus for {$scan->result_name}",
                    'reference_type' => 'scan',
                    'reference_id'   => $scan->id,
                ]);
                $totalEarned += $ptsLocation;
            }

            if ($totalEarned > 0) {
                $lockedUser->increment('reward_points', $totalEarned);
            }

            return [
                'points_earned' => $totalEarned,
                'has_location'  => $hasLocation,
                'new_balance'   => $lockedUser->fresh()->reward_points,
            ];
        });
    }

    /**
     * Award bonus points when an admin includes a user's scan in AI retraining.
     */
    public function awardForRetrain(Scan $scan): int
    {
        $settings = $this->getSettings();
        $retrainBonus = max(0, (int) ($settings['points_retrain_bonus'] ?? self::POINTS_RETRAIN_BONUS));

        if (!($settings['enabled'] ?? true) || $retrainBonus <= 0 || !$scan->user_id) {
            return 0;
        }

        // Prevent duplicate retrain bonus for the same scan
        $alreadyAwarded = PointTransaction::where('reference_type', 'scan')
            ->where('reference_id', $scan->id)
            ->where('type', 'retrain_bonus')
            ->exists();

        if ($alreadyAwarded) {
            return 0;
        }

        return DB::transaction(function () use ($scan, $retrainBonus) {
            $lockedUser = User::where('id', $scan->user_id)->lockForUpdate()->first();
            if (!$lockedUser || !$lockedUser->allow_data_training) {
                return 0;
            }

            PointTransaction::create([
                'user_id'        => $lockedUser->id,
                'points'         => $retrainBonus,
                'type'           => 'retrain_bonus',
                'description'    => "Your scan ({$scan->result_name}) was used to retrain the AI model",
                'reference_type' => 'scan',
                'reference_id'   => $scan->id,
            ]);

            $lockedUser->increment('reward_points', $retrainBonus);

            return $retrainBonus;
        });
    }

    /**
     * Calculate how many points can be applied to a subscription plan.
     */
    public function calculateDiscount(User $user, float $planAmountPhp, int $requestedPoints): array
    {
        $settings = $this->getSettings();
        $planInt = (int) round($planAmountPhp);

        if (!($settings['enabled'] ?? true)) {
            return [
                'is_full_redemption' => false,
                'points_used'        => 0,
                'discount_php'       => 0,
                'final_amount_php'   => $planInt,
                'final_centavos'     => $planInt * 100,
            ];
        }

        $available = max(0, (int) $user->reward_points);
        $minRedeem = max(1, (int) ($settings['min_redeem_points'] ?? self::MIN_REDEEM_POINTS));
        $phpPerPoint = max(0.01, (float) ($settings['php_per_point'] ?? 1.0));
        $allowFull = (bool) ($settings['allow_full_redemption'] ?? true);
        $maxPct = min(100, max(1, (int) ($settings['max_discount_percent'] ?? 100)));

        if ($requestedPoints <= 0 || $available < $minRedeem) {
            return [
                'is_full_redemption' => false,
                'points_used'        => 0,
                'discount_php'       => 0,
                'final_amount_php'   => $planInt,
                'final_centavos'     => $planInt * 100,
            ];
        }

        $pointsNeededForFull = (int) ceil($planInt / $phpPerPoint);

        // Full redemption if allowed, maxPct === 100, and user requests & has enough points
        if ($allowFull && $maxPct === 100 && $requestedPoints >= $pointsNeededForFull && $available >= $pointsNeededForFull) {
            return [
                'is_full_redemption' => true,
                'points_used'        => $pointsNeededForFull,
                'discount_php'       => $planInt,
                'final_amount_php'   => 0,
                'final_centavos'     => 0,
            ];
        }

        // Partial discount: cap by max_discount_percent and leave at least MIN_PAYMONGO_AMOUNT_PHP (₱20)
        $maxDiscountByPct = (int) floor($planInt * ($maxPct / 100));
        $maxDiscountByPaymongo = max(0, $planInt - self::MIN_PAYMONGO_AMOUNT_PHP);
        $maxDiscountPhp = min($maxDiscountByPct, $maxDiscountByPaymongo);

        $usablePoints = min($requestedPoints, $available);
        $discountPhp = (int) min($maxDiscountPhp, floor($usablePoints * $phpPerPoint));
        $actualPointsUsed = (int) ceil($discountPhp / $phpPerPoint);

        if ($actualPointsUsed < $minRedeem || $discountPhp <= 0) {
            return [
                'is_full_redemption' => false,
                'points_used'        => 0,
                'discount_php'       => 0,
                'final_amount_php'   => $planInt,
                'final_centavos'     => $planInt * 100,
            ];
        }

        $finalPhp = $planInt - $discountPhp;

        return [
            'is_full_redemption' => false,
            'points_used'        => $actualPointsUsed,
            'discount_php'       => $discountPhp,
            'final_amount_php'   => $finalPhp,
            'final_centavos'     => $finalPhp * 100,
        ];
    }

    /**
     * Atomically deduct points for a subscription discount or full redemption.
     * Idempotent when reference_type & reference_id are provided.
     */
    public function redeemPoints(
        User $user,
        int $points,
        string $type,
        string $description,
        ?string $referenceType = null,
        ?int $referenceId = null
    ): ?PointTransaction {
        if ($points <= 0) {
            return null;
        }

        if ($referenceType && $referenceId) {
            $existing = PointTransaction::where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->where('type', $type)
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($user, $points, $type, $description, $referenceType, $referenceId) {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();
            $deduct = min($points, (int) $lockedUser->reward_points);

            if ($deduct <= 0) {
                return null;
            }

            $lockedUser->decrement('reward_points', $deduct);

            return PointTransaction::create([
                'user_id'        => $lockedUser->id,
                'points'         => -$deduct,
                'type'           => $type,
                'description'    => $description,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
            ]);
        });
    }

    /**
     * Admin manual point adjustment (grant or deduct).
     */
    public function adjustPoints(User $user, int $points, string $reason): PointTransaction
    {
        return DB::transaction(function () use ($user, $points, $reason) {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();
            $current = (int) $lockedUser->reward_points;
            $delta = $points >= 0 ? $points : -min(abs($points), $current);

            if ($delta > 0) {
                $lockedUser->increment('reward_points', $delta);
            } elseif ($delta < 0) {
                $lockedUser->decrement('reward_points', abs($delta));
            }

            return PointTransaction::create([
                'user_id'     => $lockedUser->id,
                'points'      => $delta,
                'type'        => 'admin_adjustment',
                'description' => $reason,
            ]);
        });
    }

    public function getDailyRewardedScanCount(User $user): int
    {
        return PointTransaction::where('user_id', $user->id)
            ->where('type', 'scan_contribution')
            ->whereDate('created_at', now()->toDateString())
            ->count();
    }
}
