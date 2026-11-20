<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthLoginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('username')->unique()->nullable();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('role')->default('forager');
            $table->boolean('allow_data_training')->default(false);
            $table->timestamp('data_training_consented_at')->nullable();
            $table->unsignedInteger('reward_points')->default(0);
            $table->unsignedInteger('lifetime_free_scans_used')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('plan')->default('monthly');
            $table->string('status')->default('active');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_user_can_login_with_email_or_username(): void
    {
        $user = User::create([
            'name' => 'Forager Tester',
            'email' => 'forager_test@example.com',
            'username' => 'foragertest',
            'password' => Hash::make('Secret123!'),
            'role' => 'forager',
        ]);
        $user->markEmailAsVerified();

        // Login with email
        $response1 = $this->postJson('/api/login', [
            'email' => 'forager_test@example.com',
            'password' => 'Secret123!',
        ]);
        $response1->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.role', 'forager')
            ->assertJsonStructure(['token', 'user']);

        // Login with username via email field
        $response2 = $this->postJson('/api/login', [
            'email' => 'foragertest',
            'password' => 'Secret123!',
        ]);
        $response2->assertOk()
            ->assertJsonPath('user.id', $user->id);

        // Login with username via login field
        $response3 = $this->postJson('/api/login', [
            'login' => 'foragertest',
            'password' => 'Secret123!',
        ]);
        $response3->assertOk()
            ->assertJsonPath('user.id', $user->id);

        // Admin dedicated login portal test
        $admin = User::create([
            'name' => 'Admin Tester',
            'email' => 'admin_test@example.com',
            'username' => 'admintest',
            'password' => Hash::make('Secret123!'),
            'role' => 'admin',
        ]);
        $admin->markEmailAsVerified();

        // Admin attempting regular forager login should receive 403 ADMIN_PORTAL_REQUIRED
        $this->postJson('/api/login', [
            'email' => 'admin_test@example.com',
            'password' => 'Secret123!',
        ])->assertStatus(403)
          ->assertJsonPath('error', 'ADMIN_PORTAL_REQUIRED');

        // Admin using dedicated /api/admin/login endpoint
        $this->postJson('/api/admin/login', [
            'email' => 'admin_test@example.com',
            'password' => 'Secret123!',
        ])->assertOk()
          ->assertJsonPath('user.id', $admin->id)
          ->assertJsonPath('user.role', 'admin');
    }
}
