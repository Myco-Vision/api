<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScanPreviewTest extends TestCase
{
    public function test_preview_returns_subject_not_mushroom_without_classification(): void
    {
        config(['services.ml.url' => 'https://ml.example.test']);

        Http::fake([
            'https://ml.example.test/classify?detect_only=1' => Http::response([
                'is_mushroom'  => false,
                'status_label' => 'subject not mushroom',
            ], 200),
        ]);

        Sanctum::actingAs(User::factory()->make());

        $response = $this->postJson('/api/scans/preview', [
            'image' => UploadedFile::fake()->image('frame.jpg', 320, 240),
        ]);

        $response->assertOk()
            ->assertExactJson([
                'is_mushroom' => false,
                'label'       => 'subject not mushroom',
            ]);
    }

    public function test_preview_returns_subject_is_mushroom_without_classification(): void
    {
        config(['services.ml.url' => 'https://ml.example.test']);

        Http::fake([
            'https://ml.example.test/classify?detect_only=1' => Http::response([
                'is_mushroom'  => true,
                'status_label' => 'subject is mushroom',
            ], 200),
        ]);

        Sanctum::actingAs(User::factory()->make());

        $response = $this->postJson('/api/scans/preview', [
            'image' => UploadedFile::fake()->image('frame.jpg', 320, 240),
        ]);

        $response->assertOk()
            ->assertExactJson([
                'is_mushroom' => true,
                'label'       => 'subject is mushroom',
            ]);
    }

    public function test_preview_strips_classification_when_legacy_ml_payload_returned(): void
    {
        config(['services.ml.url' => 'https://ml.example.test']);

        Http::fake([
            'https://ml.example.test/classify?detect_only=1' => Http::response([
                'species'               => 'Amanita muscaria',
                'result_name'           => 'Amanita muscaria',
                'result_classification' => 'poisonous',
                'confidence'            => 94.5,
            ], 200),
        ]);

        Sanctum::actingAs(User::factory()->make());

        $response = $this->postJson('/api/scans/preview', [
            'image' => UploadedFile::fake()->image('frame.jpg', 320, 240),
        ]);

        $response->assertOk()
            ->assertExactJson([
                'is_mushroom' => true,
                'label'       => 'subject is mushroom',
            ]);
    }
}

