<?php

namespace Tests\Feature;

use App\Models\CollectionFolder;
use App\Models\Scan;
use App\Models\Species;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CollectionTest extends TestCase
{
    protected User $user;
    protected User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('forager');
            $table->boolean('allow_data_training')->default(false);
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
            $table->timestamps();
        });

        Schema::create('collection_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 20)->default('emerald');
            $table->boolean('allow_ai_training')->default(false);
            $table->timestamps();
        });

        Schema::create('collection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_folder_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['collection_folder_id', 'scan_id']);
        });

        $this->user = User::create([
            'name' => 'Alice Forager',
            'email' => 'alice@example.com',
            'password' => bcrypt('password'),
            'role' => 'forager',
        ]);

        $this->otherUser = User::create([
            'name' => 'Bob Forager',
            'email' => 'bob@example.com',
            'password' => bcrypt('password'),
            'role' => 'forager',
        ]);
    }

    public function test_user_can_list_and_create_folders(): void
    {
        Sanctum::actingAs($this->user);

        // List initial empty folders
        $response = $this->getJson('/api/collections');
        $response->assertStatus(200)->assertJsonCount(0);

        // Create folder
        $createResponse = $this->postJson('/api/collections', [
            'name' => 'Edible Finds',
            'color' => 'amber',
            'allow_ai_training' => true,
        ]);

        $createResponse->assertStatus(201)
            ->assertJson([
                'name' => 'Edible Finds',
                'color' => 'amber',
                'allow_ai_training' => true,
                'items_count' => 0,
            ]);

        // List again
        $this->getJson('/api/collections')
            ->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Edible Finds']);
    }

    public function test_user_can_add_and_remove_scans_in_folder(): void
    {
        Sanctum::actingAs($this->user);

        $folder = $this->user->collectionFolders()->create([
            'name' => 'Field Study',
            'color' => 'emerald',
            'allow_ai_training' => false,
        ]);

        $scan = Scan::create([
            'user_id' => $this->user->id,
            'image_path' => 'scans/test.jpg',
            'result_name' => 'Pleurotus pulmonarius',
            'result_classification' => 'edible',
            'confidence_level' => 88.5,
        ]);

        // Add scan to folder
        $addResponse = $this->postJson("/api/collections/{$folder->id}/scans", [
            'scan_id' => $scan->id,
        ]);
        $addResponse->assertStatus(201);

        // Verify scans in folder
        $scansResponse = $this->getJson("/api/collections/{$folder->id}/scans");
        $scansResponse->assertStatus(200)->assertJsonCount(1);

        // Verify folder IDs for scan
        $foldersForScan = $this->getJson("/api/collections/scan/{$scan->id}/folders");
        $foldersForScan->assertStatus(200)->assertExactJson([$folder->id]);

        // Remove scan from folder
        $deleteResponse = $this->deleteJson("/api/collections/{$folder->id}/scans/{$scan->id}");
        $deleteResponse->assertStatus(200);

        // Verify empty
        $this->getJson("/api/collections/{$folder->id}/scans")
            ->assertStatus(200)
            ->assertJsonCount(0);
    }

    public function test_user_cannot_access_or_modify_another_users_folder(): void
    {
        Sanctum::actingAs($this->user);

        $otherFolder = $this->otherUser->collectionFolders()->create([
            'name' => 'Bob Secret Folder',
            'color' => 'rose',
            'allow_ai_training' => false,
        ]);

        // Attempt to view Bob's folder scans
        $this->getJson("/api/collections/{$otherFolder->id}/scans")
            ->assertStatus(403);

        // Attempt to update Bob's folder
        $this->putJson("/api/collections/{$otherFolder->id}", ['name' => 'Hacked'])
            ->assertStatus(403);

        // Attempt to delete Bob's folder
        $this->deleteJson("/api/collections/{$otherFolder->id}")
            ->assertStatus(403);
    }
}
