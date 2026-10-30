<?php

namespace App\Http\Controllers;

use App\Models\Scan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AdminRetrainController extends Controller
{
    private function mlUrl(): string
    {
        return rtrim(env('ML_API_URL', 'http://127.0.0.1:5000'), '/');
    }

    private function retrainKey(): string
    {
        return env('RETRAIN_API_KEY', 'myco-retrain-secret');
    }

    /**
     * GET /api/admin/retrain/candidates
     *
     * Returns a breakdown of consented scan images available for retraining,
     * split by classification (edible vs poisonous).
     */
    public function candidates(Request $request)
    {
        // Only scans from users who gave data-training consent and that
        // have not already been used in a previous retrain run
        $base = Scan::whereHas('user', fn ($q) => $q->where('allow_data_training', true))
            ->whereIn('result_classification', ['edible', 'poisonous'])
            ->whereNotNull('image_path')
            ->whereNull('retrained_at');

        $edible = (clone $base)->where('result_classification', 'edible')
            ->with('user:id,name')
            ->latest()
            ->get()
            ->map(fn ($s) => [
                'id'             => $s->id,
                'image_url'      => asset('storage/' . $s->image_path),
                'image_path'     => $s->image_path,
                'result_name'    => $s->result_name,
                'confidence'     => round($s->confidence_level ?? 0),
                'classification' => $s->result_classification,
                'user'           => $s->user?->name ?? 'Unknown',
                'scanned_at'     => $s->created_at?->toDateString(),
            ]);

        $poisonous = (clone $base)->where('result_classification', 'poisonous')
            ->with('user:id,name')
            ->latest()
            ->get()
            ->map(fn ($s) => [
                'id'             => $s->id,
                'image_url'      => asset('storage/' . $s->image_path),
                'image_path'     => $s->image_path,
                'result_name'    => $s->result_name,
                'confidence'     => round($s->confidence_level ?? 0),
                'classification' => $s->result_classification,
                'user'           => $s->user?->name ?? 'Unknown',
                'scanned_at'     => $s->created_at?->toDateString(),
            ]);

        return response()->json([
            'edible'    => $edible,
            'poisonous' => $poisonous,
            'summary'   => [
                'edible_count'    => $edible->count(),
                'poisonous_count' => $poisonous->count(),
                'total'           => $edible->count() + $poisonous->count(),
            ],
        ]);
    }

    /**
     * POST /api/admin/retrain
     *
     * Collects all consented scan images, streams them to the Flask /retrain
     * endpoint, and returns the Flask response.
     *
     * Optional JSON body:
     *   { "edible_ids": [1,2,3], "poisonous_ids": [4,5,6] }
     *   → If provided, only those scan IDs are used. If omitted, ALL consented
     *     scans are included.
     */
    public function trigger(Request $request)
    {
        $request->validate([
            'edible_ids'    => 'sometimes|array',
            'edible_ids.*'  => 'integer',
            'poisonous_ids'    => 'sometimes|array',
            'poisonous_ids.*'  => 'integer',
        ]);

        // ── Build query ────────────────────────────────────────────────────
        // Scans already used in a previous run (retrained_at set) are never re-sent,
        // even if their IDs are submitted (e.g. from a stale browser tab).
        $baseQuery = Scan::whereHas('user', fn ($q) => $q->where('allow_data_training', true))
            ->whereIn('result_classification', ['edible', 'poisonous'])
            ->whereNotNull('image_path')
            ->whereNull('retrained_at');

        if ($request->has('edible_ids') || $request->has('poisonous_ids')) {
            $edibleIds    = $request->input('edible_ids', []);
            $poisonousIds = $request->input('poisonous_ids', []);
            $allowedIds   = array_merge($edibleIds, $poisonousIds);
            $baseQuery->whereIn('id', $allowedIds);
        }

        $scans = $baseQuery->get();

        if ($scans->isEmpty()) {
            return response()->json([
                'message' => 'No consented scan images found for retraining.',
            ], 422);
        }

        // ── Build multipart request to Flask ───────────────────────────────
        $httpRequest = Http::timeout(30)
            ->withHeaders(['X-Retrain-Key' => $this->retrainKey()]);

        $edibleCount    = 0;
        $poisonousCount = 0;

        foreach ($scans as $scan) {
            $diskPath = Storage::disk('public')->path($scan->image_path);

            if (!file_exists($diskPath)) {
                Log::warning("Retrain: image file missing for scan #{$scan->id}: {$diskPath}");
                continue;
            }

            $label = $scan->result_classification; // "edible" or "poisonous"
            $httpRequest = $httpRequest->attach(
                'images[]',
                file_get_contents($diskPath),
                basename($diskPath)
            );
            // We append the label matching each image
            // (multipart labels[] is handled via form fields below)
            if ($label === 'edible') {
                $edibleCount++;
            } else {
                $poisonousCount++;
            }
        }

        // Rebuild with both images and labels in the correct order
        // (Laravel's Http client doesn't support mixed attach + multipart easily,
        //  so we use a raw Guzzle multipart array.)
        $multipart = [];
        $sentScanIds = [];
        foreach ($scans as $scan) {
            $diskPath = Storage::disk('public')->path($scan->image_path);
            if (!file_exists($diskPath)) {
                continue;
            }
            $sentScanIds[] = $scan->id;
            $multipart[] = [
                'name'     => 'images[]',
                'contents' => fopen($diskPath, 'r'),
                'filename' => basename($diskPath),
            ];
            $multipart[] = [
                'name'     => 'labels[]',
                'contents' => $scan->result_classification,
            ];
        }

        if (empty($multipart)) {
            return response()->json([
                'message' => 'All image files are missing from storage.',
            ], 422);
        }

        try {
            $guzzle   = new \GuzzleHttp\Client(['timeout' => 30]);
            $response = $guzzle->post($this->mlUrl() . '/retrain', [
                'headers'   => ['X-Retrain-Key' => $this->retrainKey()],
                'multipart' => $multipart,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            // Flask accepted the job: its images are now in the training dataset,
            // so flag these scans to keep them out of future retrain runs.
            Scan::whereIn('id', $sentScanIds)->update(['retrained_at' => now()]);

            return response()->json(array_merge($body, [
                'edible_queued'    => $edibleCount,
                'poisonous_queued' => $poisonousCount,
            ]), $response->getStatusCode());

        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            Log::error('Retrain: Flask service offline', ['msg' => $e->getMessage()]);
            return response()->json(['message' => 'ML service is offline.'], 503);
        } catch (\Exception $e) {
            Log::error('Retrain: unexpected error', ['msg' => $e->getMessage()]);
            return response()->json(['message' => 'Retrain request failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/admin/retrain/status
     *
     * Proxies Flask's /retrain/status back to the Vue admin page.
     */
    public function status()
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['X-Retrain-Key' => $this->retrainKey()])
                ->get($this->mlUrl() . '/retrain/status');

            return response()->json($response->json(), $response->status());

        } catch (\Exception $e) {
            Log::error('Retrain status check failed', ['msg' => $e->getMessage()]);
            return response()->json([
                'current' => [
                    'state' => 'unknown',
                    'error' => 'ML service unreachable.',
                ],
                'history' => [],
            ], 503);
        }
    }
}
