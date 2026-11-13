<?php

namespace Tests\Feature;

use App\Models\Scan;
use App\Models\User;
use App\Services\RewardService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RewardPointsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('role')->default('user');
            $table->boolean('allow_data_training')->default(false);
            $table->timestamp('data_training_consented_at')->nullable();
            $table->unsignedInteger('reward_points')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('species', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('scientific_name')->nullable();
            $table->timestamps();
        });

        Schema::create('scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('species_id')->nullable();
            $table->string('image_path');
            $table->string('result_name');
            $table->string('result_classification');
            $table->float('confidence_level')->default(0);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('location_name')->nullable();
            $table->string('location_source', 20)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('retrained_at')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('deleted_user_email')->nullable();
            $table->string('plan');
            $table->string('status')->default('pending');
            $table->boolean('auto_renew')->default(true);
            $table->string('payment_status')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('currency', 10)->default('PHP');
            $table->string('paymongo_session_id')->nullable();
            $table->string('paymongo_payment_id')->nullable();
            $table->string('payment_method')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('points');
            $table->string('type', 50);
            $table->string('description');
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamps();
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function test_consented_geotagged_scan_awards_points_and_respects_daily_cap(): void
    {
        $service = new RewardService();

        $unconsented = User::factory()->create(['allow_data_training' => false]);
        $scan0 = Scan::create([
            'user_id' => $unconsented->id,
            'image_path' => 'scans/a.jpg',
            'result_name' => 'Oyster',
            'result_classification' => 'edible',
            'confidence_level' => 95,
        ]);
        $this->assertSame(0, $service->awardForScan($unconsented, $scan0)['points_earned']);

        $user = User::factory()->create(['allow_data_training' => true]);

        // 5 geotagged scans = 5 * (5 + 3) = 40 pts
        for ($i = 0; $i < 5; $i++) {
            $scan = Scan::create([
                'user_id' => $user->id,
                'image_path' => "scans/m{$i}.jpg",
                'result_name' => 'Oyster',
                'result_classification' => 'edible',
                'confidence_level' => 90,
                'latitude' => 14.5995,
                'longitude' => 120.9842,
            ]);
            $res = $service->awardForScan($user, $scan);
            $this->assertSame(8, $res['points_earned']);
        }

        // 6th scan hits daily cap
        $scan6 = Scan::create([
            'user_id' => $user->id,
            'image_path' => 'scans/m6.jpg',
            'result_name' => 'Oyster',
            'result_classification' => 'edible',
            'confidence_level' => 90,
        ]);
        $this->assertSame(0, $service->awardForScan($user, $scan6)['points_earned']);
        $this->assertSame(40, $user->fresh()->reward_points);
    }

    public function test_retrain_bonus_is_awarded_once_per_scan(): void
    {
        $service = new RewardService();
        $user = User::factory()->create(['allow_data_training' => true]);

        $scan = Scan::create([
            'user_id' => $user->id,
            'image_path' => 'scans/retrain.jpg',
            'result_name' => 'Shiitake',
            'result_classification' => 'edible',
            'confidence_level' => 92,
        ]);

        $this->assertSame(15, $service->awardForRetrain($scan));
        $this->assertSame(0, $service->awardForRetrain($scan));
        $this->assertSame(15, $user->fresh()->reward_points);
    }

    public function test_full_and_partial_subscription_redemption(): void
    {
        $user = User::factory()->create([
            'allow_data_training' => true,
            'reward_points' => 200,
        ]);

        // Partial discount calculation check (50 pts on ₱149 -> ₱99)
        $service = new RewardService();
        $partial = $service->calculateDiscount($user, 149, 50);
        $this->assertFalse($partial['is_full_redemption']);
        $this->assertSame(50, $partial['points_used']);
        $this->assertSame(99, $partial['final_amount_php']);

        // Full redemption via API (149 pts -> free monthly subscription)
        $response = $this->actingAs($user, 'sanctum')->postJson('/api/subscription/checkout', [
            'plan' => 'monthly',
            'points_to_use' => 200,
        ]);

        $response->assertOk()
            ->assertJson([
                'redeemed_full' => true,
            ]);

        $this->assertSame(51, $user->fresh()->reward_points);
        $this->assertTrue($user->fresh()->is_subscribed);

        // Rewards endpoint returns remaining balance and transaction
        $rewardsRes = $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/rewards');
        $rewardsRes->assertOk()
            ->assertJson([
                'reward_points' => 51,
            ]);
    }

    public function test_admin_can_update_reward_rules_and_adjust_user_points(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['allow_data_training' => true, 'reward_points' => 10]);

        // Update rules: 12 pts per scan, 50% max discount, no full redemption
        $res = $this->actingAs($admin, 'sanctum')->putJson('/api/admin/rewards/settings', [
            'enabled'               => true,
            'points_per_scan'       => 12,
            'points_location_bonus' => 4,
            'points_retrain_bonus'  => 20,
            'daily_scan_cap'        => 3,
            'min_redeem_points'     => 10,
            'php_per_point'         => 1.0,
            'allow_full_redemption' => false,
            'max_discount_percent'  => 50,
        ]);
        $res->assertOk();

        // Manual adjustment: +190 pts -> total 200
        $adjRes = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/rewards/adjust', [
            'user_identifier' => $user->email,
            'points'          => 190,
            'reason'          => 'Community bonus',
        ]);
        $adjRes->assertOk()->assertJson(['new_balance' => 200]);

        // Discount respects 50% cap (50% of 149 = 74) and disallows full redemption
        $service = new RewardService();
        $discount = $service->calculateDiscount($user->fresh(), 149, 200);
        $this->assertFalse($discount['is_full_redemption']);
        $this->assertSame(74, $discount['discount_php']);

        // Scan awards updated 12 + 4 = 16 pts
        $scan = Scan::create([
            'user_id' => $user->id,
            'image_path' => 'scans/custom.jpg',
            'result_name' => 'Oyster',
            'result_classification' => 'edible',
            'confidence_level' => 95,
            'latitude' => 14.5,
            'longitude' => 121.0,
        ]);
        $this->assertSame(16, $service->awardForScan($user->fresh(), $scan)['points_earned']);
    }
}
