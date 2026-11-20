<?php

namespace Tests\Feature;

use App\Models\Scan;
use App\Models\Species;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminRetrainUploadExportTest extends TestCase
{
    protected User $admin;
    protected User $forager;

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
            $table->string('image_hash')->nullable();
            $table->string('result_name');
            $table->string('result_classification');
            $table->float('confidence_level')->default(0);
            $table->timestamp('retrained_at')->nullable();
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

        Schema::create('scan_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->string('annotation_status')->default('pending');
            $table->string('predicted_species')->nullable();
            $table->string('verified_species')->nullable();
            $table->float('confidence_score')->default(0);
            $table->unsignedInteger('points_awarded')->default(0);
            $table->timestamps();
        });

        $this->admin = User::create([
            'name' => 'Super Admin',
            'email' => 'admin@mycovision.test',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]);

        $this->forager = User::create([
            'name' => 'Field Forager',
            'email' => 'forager@mycovision.test',
            'password' => bcrypt('password'),
            'role' => 'forager',
        ]);

        Storage::fake('public');
    }

    public function test_admin_can_upload_edible_and_poisonous_images_from_computer(): void
    {
        Sanctum::actingAs($this->admin);

        $species = Species::create([
            'name' => 'Pleurotus pulmonarius',
            'scientific_name' => 'Pleurotus pulmonarius',
            'classification' => 'edible',
        ]);

        $file1 = UploadedFile::fake()->image('oyster_1.jpg', 400, 400);
        $file2 = UploadedFile::fake()->image('oyster_2.jpg', 400, 400);

        $response = $this->postJson('/api/admin/retrain/upload', [
            'classification' => 'edible',
            'species_id'     => $species->id,
            'species_name'   => 'Phoenix Oyster',
            'images'         => [$file1, $file2],
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'uploaded_count' => 2,
                'classification' => 'edible',
                'species_name'   => 'Phoenix Oyster',
            ]);

        $this->assertDatabaseCount('scans', 2);

        // Upload poisonous image
        $fileP = UploadedFile::fake()->image('amanita.jpg', 400, 400);
        $responseP = $this->postJson('/api/admin/retrain/upload', [
            'classification' => 'poisonous',
            'species_name'   => 'Amanita virosa',
            'images'         => [$fileP],
        ]);

        $responseP->assertStatus(201)
            ->assertJson([
                'uploaded_count' => 1,
                'classification' => 'poisonous',
            ]);

        $this->assertDatabaseCount('scans', 3);

        // Verify candidates endpoint now reflects both uploads
        $candidates = $this->getJson('/api/admin/retrain/candidates');
        $candidates->assertStatus(200);
        $candidates->assertJsonPath('summary.edible_count', 2);
        $candidates->assertJsonPath('summary.poisonous_count', 1);
        $candidates->assertJsonPath('summary.total', 3);
    }

    public function test_forager_cannot_upload_retrain_images(): void
    {
        Sanctum::actingAs($this->forager);

        $file = UploadedFile::fake()->image('test.jpg', 400, 400);

        $response = $this->postJson('/api/admin/retrain/upload', [
            'classification' => 'edible',
            'images'         => [$file],
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_export_retraining_images_zip(): void
    {
        Sanctum::actingAs($this->admin);

        $file = UploadedFile::fake()->image('test_edible.jpg', 200, 200);
        $path = $file->store('scans/admin', 'public');

        Scan::create([
            'user_id'               => $this->admin->id,
            'image_path'            => $path,
            'result_name'           => 'Pleurotus ostreatus',
            'result_classification' => 'edible',
            'confidence_level'      => 95.0,
            'retrained_at'          => null,
        ]);

        $response = $this->get('/api/admin/retrain/export?classification=edible');
        $response->assertStatus(200);
        $this->assertStringContainsString('application/zip', (string) $response->headers->get('content-type'));
    }
}

