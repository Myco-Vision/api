<?php

namespace Tests\Feature;

use App\Models\Scan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PlanConfigService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SubscriptionLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('point_transactions');
        Schema::dropIfExists('refund_requests');
        Schema::dropIfExists('subscription_events');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('scans');
        Schema::dropIfExists('species');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('forager');
            $table->unsignedInteger('reward_points')->default(0);
            $table->unsignedInteger('lifetime_free_scans_used')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('result_name')->default('Agaricus bisporus');
            $table->string('result_classification')->default('edible');
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('deleted_user_email')->nullable();
            $table->string('plan');
            $table->string('status')->default('pending');
            $table->boolean('auto_renew')->default(false);
            $table->decimal('price', 10, 2);
            $table->string('currency', 10)->default('PHP');
            $table->string('paymongo_session_id')->nullable();
            $table->string('paymongo_payment_id')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_status')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('deleted_user_email')->nullable();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('action');
            $table->json('details')->nullable();
            $table->timestamps();
        });
    }

    public function test_free_scans_quota_cannot_be_exploited_by_deleting_scans(): void
    {
        $user = User::create([
            'name'                     => 'Explorer John',
            'email'                    => 'john@example.com',
            'password'                 => bcrypt('password'),
            'lifetime_free_scans_used' => 0,
        ]);

        $this->assertTrue($user->canScan());
        $this->assertSame(3, $user->remainingFreeScans());

        // Perform 3 scans
        $user->increment('lifetime_free_scans_used');
        $scan1 = Scan::create(['user_id' => $user->id]);

        $user->increment('lifetime_free_scans_used');
        $scan2 = Scan::create(['user_id' => $user->id]);

        $user->increment('lifetime_free_scans_used');
        $scan3 = Scan::create(['user_id' => $user->id]);

        $user->refresh();
        $this->assertSame(3, $user->freeScansUsed());
        $this->assertSame(0, $user->remainingFreeScans());
        $this->assertFalse($user->canScan());

        // User deletes a scan from history
        $scan1->delete();

        // Count of active scans in DB dropped to 2, but lifetime free scans used remains 3!
        $this->assertSame(2, $user->scans()->count());
        $user->refresh();
        $this->assertSame(3, $user->freeScansUsed());
        $this->assertSame(0, $user->remainingFreeScans());
        $this->assertFalse($user->canScan());
    }

    public function test_subscription_duration_stacks_on_top_of_existing_active_period(): void
    {
        $user = User::create([
            'name'     => 'Mycologist Jane',
            'email'    => 'jane@example.com',
            'password' => bcrypt('password'),
        ]);

        $initialExpiry = Carbon::now()->addDays(20);

        // Current active monthly subscription with 20 days remaining
        $activeSub = Subscription::create([
            'user_id'             => $user->id,
            'plan'                => 'monthly',
            'status'              => 'active',
            'price'               => 149.00,
            'starts_at'           => Carbon::now()->subDays(10),
            'expires_at'          => $initialExpiry,
            'paymongo_session_id' => 'cs_active_1',
        ]);

        $this->assertTrue($user->hasActiveSubscription());

        // User purchases an Annual plan via mock verification
        $res = $this->actingAs($user)->postJson('/api/subscription/verify', [
            'session_id' => 'cs_mock_annual_renew',
            'plan'       => 'annual',
        ]);

        $res->assertOk();

        // The new subscription's expiry must be initialExpiry + 1 year (not now + 1 year)
        $newSub = $user->activeSubscription;
        $this->assertNotNull($newSub);
        $this->assertSame('annual', $newSub->plan);
        $this->assertTrue($newSub->expires_at->gt(Carbon::now()->addMonths(12)));
        $this->assertEquals($initialExpiry->copy()->addYear()->toDateTimeString(), $newSub->expires_at->toDateTimeString());

        // Old subscription should be superseded
        $this->assertSame('cancelled', $activeSub->fresh()->status);
        $this->assertTrue($activeSub->fresh()->metadata['stacked_into_new_period'] ?? false);
    }

    public function test_process_expirations_command_updates_expired_subscriptions(): void
    {
        Mail::fake();

        $user = User::create([
            'name'     => 'Alice Forager',
            'email'    => 'alice@example.com',
            'password' => bcrypt('password'),
        ]);

        // Subscription that expired yesterday
        $expiredSub = Subscription::create([
            'user_id'    => $user->id,
            'plan'       => 'monthly',
            'status'     => 'active',
            'price'      => 149.00,
            'starts_at'  => Carbon::now()->subDays(31),
            'expires_at' => Carbon::now()->subDay(),
        ]);

        // Subscription expiring in 5 days
        $expiringSub = Subscription::create([
            'user_id'    => $user->id,
            'plan'       => 'monthly',
            'status'     => 'active',
            'price'      => 149.00,
            'starts_at'  => Carbon::now()->subDays(25),
            'expires_at' => Carbon::now()->addDays(5),
        ]);

        // Run the command
        Artisan::call('subscriptions:process-expirations');

        // Expired subscription should now have status = 'expired'
        $this->assertSame('expired', $expiredSub->fresh()->status);
        $this->assertFalse($expiredSub->fresh()->auto_renew);

        // 5-day expiring subscription should remain active but have reminder logged in metadata
        $this->assertSame('active', $expiringSub->fresh()->status);
        $this->assertNotNull($expiringSub->fresh()->metadata['reminder_7d_sent_at'] ?? null);
    }

    public function test_plans_api_returns_dynamic_plans_and_correct_free_limit(): void
    {
        $res = $this->getJson('/api/subscription/plans');
        $res->assertOk();

        $res->assertJsonStructure([
            'free_tier' => [
                'name',
                'price',
                'scans_limit',
                'features',
            ],
            'plans' => [
                'monthly',
                'annual',
            ],
        ]);

        $this->assertSame(PlanConfigService::freeScanLimit(), $res->json('free_tier.scans_limit'));
    }
}

