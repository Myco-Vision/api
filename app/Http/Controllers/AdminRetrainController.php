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
        return rtrim(config('services.ml.url'), '/');
    }

    private function retrainKey(): string
    {
        return (string) config('services.ml.retrain_key');
    }

    /**
     * Read raw image bytes + filename for a scan across local storage, S3/Supabase,
     * or direct HTTP URLs so retraining works on both local disk and serverless S3.
     */
    private function readScanImageContents(Scan $scan): ?array
    {
        $imagePath = (string) $scan->image_path;
        if ($imagePath === '') {
            return null;
        }

        // 1. Direct HTTP(S) URL stored in image_path
        if (str_starts_with($imagePath, 'http://') || str_starts_with($imagePath, 'https://')) {
            try {
                $res = Http::timeout(15)->get($imagePath);
                if ($res->successful() && $res->body() !== '') {
                    $pathPart = parse_url($imagePath, PHP_URL_PATH) ?: '';
                    return [
                        'contents' => $res->body(),
                        'filename' => basename($pathPart) ?: "scan_{$scan->id}.jpg",
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning("Retrain: HTTP fetch failed for scan #{$scan->id}: {$e->getMessage()}");
            }

            return null;
        }

        // 2. Configured 'public' filesystem disk (works for both 'local' and 's3' drivers)
        $disk = Storage::disk('public');
        try {
            if ($disk->exists($imagePath)) {
                $contents = $disk->get($imagePath);
                if ($contents !== null && $contents !== '') {
                    return [
                        'contents' => $contents,
                        'filename' => basename($imagePath),
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Retrain: storage read failed for scan #{$scan->id} ({$imagePath}): {$e->getMessage()}");
        }

        // 3. Fallback: fetch from public S3/Supabase URL if configured while using local disk
        $publicBaseUrl = config('filesystems.disks.s3.url') ?: env('AWS_URL');
        if ($publicBaseUrl) {
            $remoteUrl = rtrim((string) $publicBaseUrl, '/') . '/' . ltrim($imagePath, '/');
            try {
                $res = Http::timeout(15)->get($remoteUrl);
                if ($res->successful() && $res->body() !== '') {
                    return [
                        'contents' => $res->body(),
                        'filename' => basename($imagePath),
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning("Retrain: remote fallback fetch failed for scan #{$scan->id}: {$e->getMessage()}");
            }
        }

        Log::warning("Retrain: image file missing for scan #{$scan->id}: {$imagePath}");
        return null;
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
                'image_url'      => $s->image_url,
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
                'image_url'      => $s->image_url,
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
        $multipart      = [];
        $sentScanIds    = [];
        $edibleCount    = 0;
        $poisonousCount = 0;

        foreach ($scans as $scan) {
            $fileData = $this->readScanImageContents($scan);
            if ($fileData === null) {
                continue;
            }

            $sentScanIds[] = $scan->id;
            $multipart[] = [
                'name'     => 'images[]',
                'contents' => $fileData['contents'],
                'filename' => $fileData['filename'],
            ];
            $multipart[] = [
                'name'     => 'labels[]',
                'contents' => $scan->result_classification,
            ];

            if ($scan->result_classification === 'edible') {
                $edibleCount++;
            } else {
                $poisonousCount++;
            }
        }

        if (empty($multipart)) {
            $hint = config('filesystems.disks.public.driver') === 'local'
                ? ' Check that the scan files exist under storage/app/public or configure S3/Supabase storage.'
                : '';

            return response()->json([
                'message' => 'All image files are missing from storage.' . $hint,
            ], 422);
        }

        try {
            $guzzle   = new \GuzzleHttp\Client(['timeout' => 30]);
            $response = $guzzle->post($this->mlUrl() . '/retrain', [
                'headers'   => ['X-Retrain-Key' => $this->retrainKey()],
                'multipart' => $multipart,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            $body = is_array($body) ? $body : [];

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
