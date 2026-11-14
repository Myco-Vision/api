<?php

namespace Tests\Feature;

use App\Models\Scan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ForagingMapTest extends TestCase
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
            $table->string('classification')->nullable();
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
            $table->boolean('is_public_sighting')->default(false);
            $table->timestamp('retrained_at')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('plan');
            $table->string('status')->default('pending');
            $table->decimal('price', 10, 2)->default(0);
            $table->string('currency', 10)->default('PHP');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_personal_map_returns_pinned_and_unpinned_scans_and_gates_community(): void
    {
        $freeUser = User::factory()->create();

        Scan::create([
            'user_id' => $freeUser->id,
            'image_path' => 'scans/1.jpg',
            'result_name' => 'Oyster',
            'result_classification' => 'edible',
            'confidence_level' => 92,
            'latitude' => 14.5995123,
            'longitude' => 120.9842123,
            'location_name' => 'Manila',
        ]);

        Scan::create([
            'user_id' => $freeUser->id,
            'image_path' => 'scans/2.jpg',
            'result_name' => 'Amanita',
            'result_classification' => 'poisonous',
            'confidence_level' => 88,
        ]);

        $mine = $this->actingAs($freeUser, 'sanctum')->getJson('/api/scans/map?scope=mine');
        $mine->assertOk()
            ->assertJsonCount(1, 'pins')
            ->assertJsonCount(1, 'unpinned_scans');

        // Community scope is gated for free users
        $community = $this->actingAs($freeUser, 'sanctum')->getJson('/api/scans/map?scope=community');
        $community->assertStatus(403)
            ->assertJson(['error' => 'PREMIUM_REQUIRED']);
    }

    public function test_community_map_fuzzes_coordinates_and_owner_can_update_details(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();

        Subscription::create([
            'user_id' => $viewer->id,
            'plan' => 'monthly',
            'status' => 'active',
            'price' => 149,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDays(29),
        ]);

        $scan = Scan::create([
            'user_id' => $owner->id,
            'image_path' => 'scans/pub.jpg',
            'result_name' => 'Shiitake',
            'result_classification' => 'edible',
            'confidence_level' => 95,
            'latitude' => 14.1648912,
            'longitude' => 121.2413456,
            'location_name' => 'Los Banos',
            'notes' => 'Secret oak log',
            'is_public_sighting' => false,
        ]);

        // Non-owner cannot update details
        $this->actingAs($viewer, 'sanctum')
            ->patchJson("/api/scans/{$scan->id}/details", ['notes' => 'Hacked'])
            ->assertStatus(403);

        // Owner updates notes and makes sighting public
        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/scans/{$scan->id}/details", [
                'notes' => 'Found after rain',
                'is_public_sighting' => true,
            ])
            ->assertOk()
            ->assertJson([
                'notes' => 'Found after rain',
                'is_public_sighting' => true,
            ]);

        // Viewer sees fuzzed coordinates (2 decimal places) and no private notes
        $res = $this->actingAs($viewer, 'sanctum')->getJson('/api/scans/map?scope=community');
        $res->assertOk()
            ->assertJsonCount(1, 'pins')
            ->assertJsonPath('pins.0.latitude', 14.16)
            ->assertJsonPath('pins.0.longitude', 121.24)
            ->assertJsonPath('pins.0.notes', null)
            ->assertJsonPath('pins.0.is_owner', false);
    }
}
