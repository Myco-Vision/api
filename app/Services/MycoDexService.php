<?php

namespace App\Services;

use App\Models\Scan;
use App\Models\ScanContribution;
use App\Models\Species;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserMycodex;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MycoDexService
{
    /**
     * Handle gamification and contribution staging on scan submission.
     */
    public function handleScanSubmission(User $user, Scan $scan, array $mlData): ?array
    {
        // 1. Consent and OOD checks
        if (!$user->allow_data_training || $scan->result_classification === 'not_mushroom') {
            return [
                'eligible'              => false,
                'reason'                => !$user->allow_data_training ? 'consent_disabled' : 'not_mushroom',
                'points_awarded'        => 0,
                'is_new_species'        => false,
                'pending_points_total'  => (int) ($user->pending_points ?? 0),
                'verified_points_total' => (int) ($user->verified_points ?? 0),
            ];
        }

        // 2. Identify species name (prefer official species catalog name if linked)
        $speciesName = $scan->species?->name 
            ?? $mlData['scientific_name'] 
            ?? $mlData['result_name'] 
            ?? $scan->result_name 
            ?? 'Unknown Mushroom';

        // 3. Normalize confidence (0.00 to 1.00)
        $rawConf = (float) ($scan->confidence_level ?? 0);
        $confScore = $rawConf > 1.0 ? ($rawConf / 100.0) : $rawConf;

        return DB::transaction(function () use ($user, $scan, $speciesName, $confScore) {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();

            // 4. Check user_mycodex collection (case-insensitive and trimmed)
            $cleanSpeciesName = trim((string) $speciesName);
            $mycodexEntry = UserMycodex::where('user_id', $lockedUser->id)
                ->where(function ($q) use ($cleanSpeciesName) {
                    $q->where('species_name', $cleanSpeciesName)
                      ->orWhereRaw('LOWER(species_name) = ?', [strtolower($cleanSpeciesName)]);
                })
                ->first();

            $isNewSpecies = is_null($mycodexEntry);

            if (!$isNewSpecies) {
                // Increment scan count on the existing entry
                $mycodexEntry->increment('scan_count');
                return [
                    'eligible'                => false,
                    'reason'                  => 'already_unlocked',
                    'species_name'            => $speciesName,
                    'is_new_species'          => false,
                    'points_awarded'          => 0,
                    'scan_count'              => (int) $mycodexEntry->scan_count,
                    'pending_points_total'    => (int) $lockedUser->pending_points,
                    'verified_points_total'   => (int) $lockedUser->verified_points,
                    'badge'                   => 'Specimen Already Unlocked',
                    'message'                 => 'This specimen is already unlocked in your MycoDex. No duplicate points awarded.',
                ];
            }

            try {
                $mycodexEntry = UserMycodex::create([
                    'user_id'          => $lockedUser->id,
                    'species_name'     => $cleanSpeciesName,
                    'first_scanned_at' => now(),
                    'scan_count'       => 1,
                    'cover_image_path' => $scan->image_path,
                ]);
            } catch (\Throwable $e) {
                $mycodexEntry = UserMycodex::where('user_id', $lockedUser->id)
                    ->whereRaw('LOWER(species_name) = ?', [strtolower($cleanSpeciesName)])
                    ->first();
                $mycodexEntry?->increment('scan_count');
                $isNewSpecies = false;
            }

            // 5. Dynamic points calculation
            // Base scan: +2 for standard high-confidence (>= 0.80)
            // Active learning / borderline: +10 if in 0.60 - 0.79 band
            $isBorderline = ($confScore >= 0.60 && $confScore < 0.80);
            $basePoints = $isBorderline ? 10 : 2;

            // New species discovery bonus: custom unlock_points configured on specimen or default +5
            $discoveryBonus = 0;
            if ($isNewSpecies) {
                $cleanSpecimenName = trim($speciesName);
                $specimen = Species::whereRaw('LOWER(name) = ?', [strtolower($cleanSpecimenName)])
                    ->orWhereRaw('LOWER(scientific_name) = ?', [strtolower($cleanSpecimenName)])
                    ->first();
                $discoveryBonus = (int) ($specimen?->unlock_points ?: 5);
            }
            $totalPoints = $basePoints + $discoveryBonus;

            // 6. Award to pending_points (Anti-cheat: remains pending until admin approves)
            $lockedUser->increment('pending_points', $totalPoints);

            // 7. Create ScanContribution record
            $contribution = ScanContribution::create([
                'scan_id'                 => $scan->id,
                'user_id'                 => $lockedUser->id,
                'image_path'              => $scan->image_path,
                'predicted_species'       => $speciesName,
                'verified_species'        => null,
                'confidence_score'        => round($confScore, 4),
                'annotation_status'       => 'pending',
                'points_awarded'          => $totalPoints,
                'is_new_species_for_user' => $isNewSpecies,
            ]);

            $freshUser = $lockedUser->fresh();

            return [
                'eligible'                => true,
                'contribution_id'         => $contribution->id,
                'species_name'            => $speciesName,
                'is_new_species'          => $isNewSpecies,
                'is_borderline'           => $isBorderline,
                'base_points'             => $basePoints,
                'discovery_bonus'         => $discoveryBonus,
                'points_awarded'          => $totalPoints,
                'scan_count'              => $mycodexEntry->scan_count ?? 1,
                'pending_points_total'    => (int) $freshUser->pending_points,
                'verified_points_total'   => (int) $freshUser->verified_points,
                'badge'                   => $isNewSpecies ? 'Unlocked in MycoDex!' : 'Specimen Count +1',
                'message'                 => "+{$totalPoints} Pending Points earned — awaiting expert verification.",
            ];
        });
    }

    /**
     * Get user's MycoDex collection and catalog discovery progress.
     */
    public function getMycoDexData(User $user): array
    {
        $mycodexEntries = UserMycodex::where('user_id', $user->id)
            ->get()
            ->keyBy(fn ($item) => strtolower(trim($item->species_name)));

        // Get full catalog of supported mushroom species active in MycoDex
        $catalogSpecies = Species::where('is_active_mycodex', true)
            ->orWhereNull('is_active_mycodex')
            ->orderBy('name')
            ->get();

        $speciesGrid = $catalogSpecies->map(function ($species) use ($mycodexEntries) {
            $keySci = strtolower(trim((string) ($species->scientific_name ?? '')));
            $keyCom = strtolower(trim((string) ($species->name ?? '')));

            $entry = ($keySci !== '' ? $mycodexEntries->get($keySci) : null) ?: ($keyCom !== '' ? $mycodexEntries->get($keyCom) : null);
            $isDiscovered = !is_null($entry);

            return [
                'id'               => $species->id,
                'scientific_name'  => $species->scientific_name,
                'common_name'      => $species->name,
                'classification'   => $species->classification,
                'description'      => $species->description,
                'habitat'          => $species->habitat,
                'rarity'           => $species->rarity ?: 'common',
                'unlock_points'    => (int) ($species->unlock_points ?: 5),
                'unlock_hint'      => $species->unlock_hint,
                'created_at'       => $species->created_at?->toIso8601String(),
                'is_new_target'    => $species->created_at ? $species->created_at->isAfter(now()->subDays(7)) : false,
                'reference_image_url'  => $species->image_url,
                'silhouette_url'       => $species->silhouette_url ?: null,
                'silhouette_image_url' => $species->silhouette_url ?: null,
                'is_discovered'    => $isDiscovered,
                'scan_count'       => $isDiscovered ? (int) $entry->scan_count : 0,
                'first_scanned_at' => $isDiscovered ? $entry->first_scanned_at?->toIso8601String() : null,
                'cover_image_url'  => $isDiscovered ? ($entry->cover_image_url ?: $species->image_url) : null,
            ];
        });

        // Also include any user-discovered species that might not be in the base catalog table
        foreach ($mycodexEntries as $key => $entry) {
            $alreadyIncluded = $speciesGrid->contains(function ($s) use ($entry) {
                $eName = strtolower(trim((string) $entry->species_name));
                return strtolower(trim((string) ($s['scientific_name'] ?? ''))) === $eName
                    || strtolower(trim((string) ($s['common_name'] ?? ''))) === $eName;
            });

            if (!$alreadyIncluded) {
                $speciesGrid->push([
                    'id'               => 'custom_' . $entry->id,
                    'scientific_name'  => $entry->species_name,
                    'common_name'      => $entry->species_name,
                    'classification'   => 'wild',
                    'description'      => 'Discovered in the wild by you.',
                    'habitat'          => 'Wild specimen',
                    'is_discovered'    => true,
                    'scan_count'       => (int) $entry->scan_count,
                    'first_scanned_at' => $entry->first_scanned_at?->toIso8601String(),
                    'cover_image_url'  => $entry->cover_image_url,
                ]);
            }
        }

        $spendablePoints = (int) ($user->reward_points ?? 0);
        $pendingPoints = (int) ($user->pending_points ?? 0);
        $pointsRequired = 35;
        $progressPct = min(100, round(($spendablePoints / $pointsRequired) * 100));

        $activeSub = $user->activeSubscription;

        $latestActive = $catalogSpecies->sortByDesc('id')->first();

        return [
            'reward_points'    => $spendablePoints,
            'verified_points'  => $spendablePoints, // Unified for frontend compatibility
            'pending_points'   => $pendingPoints,
            'points_required'  => $pointsRequired,
            'can_redeem'       => $spendablePoints >= $pointsRequired,
            'progress_percent' => $progressPct,
            'subscription'     => [
                'is_subscribed' => $user->hasActiveSubscription(),
                'tier'          => $user->subscription_tier,
                'expires_at'    => $activeSub?->expires_at?->toIso8601String(),
            ],
            'latest_specimen'  => $latestActive ? [
                'id'              => $latestActive->id,
                'common_name'     => $latestActive->name,
                'scientific_name' => $latestActive->scientific_name,
                'rarity'          => $latestActive->rarity ?: 'common',
                'unlock_points'   => (int) ($latestActive->unlock_points ?: 5),
                'unlock_hint'     => $latestActive->unlock_hint,
                'created_at'      => $latestActive->created_at?->toIso8601String(),
            ] : null,
            'species_discovered_count' => $mycodexEntries->count(),
            'total_species_count'      => $speciesGrid->count(),
            'species_grid'             => $speciesGrid->values(),
        ];
    }

    /**
     * Redeem 35 reward points for a 7-day Pro subscription pass.
     * Uses the unified RewardService engine.
     */
    public function redeemSubscription(User $user): array
    {
        return (new RewardService())->redeemProPass($user, 7);
    }
}
