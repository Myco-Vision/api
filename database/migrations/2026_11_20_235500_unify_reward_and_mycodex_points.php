<?php

use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Unify existing verified_points into reward_points with transaction records
        DB::transaction(function () {
            $usersWithVerified = User::where('verified_points', '>', 0)->lockForUpdate()->get();

            foreach ($usersWithVerified as $user) {
                $points = (int) $user->verified_points;
                if ($points <= 0) {
                    continue;
                }

                $user->increment('reward_points', $points);
                $user->update(['verified_points' => 0]);

                PointTransaction::create([
                    'user_id'        => $user->id,
                    'points'         => $points,
                    'type'           => 'legacy_points_unification',
                    'description'    => "Unified {$points} legacy MycoDex verified points into main reward wallet",
                    'reference_type' => 'migration',
                    'reference_id'   => null,
                ]);
            }
        });
    }

    public function down(): void
    {
        // No-op for down: unified points remain safely in reward_points
    }
};

