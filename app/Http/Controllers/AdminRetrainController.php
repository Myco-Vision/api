<?php

namespace App\Http\Controllers;

use App\Models\RetrainRun;
use App\Models\Scan;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class AdminRetrainController extends Controller
{
    private const PENDING_SCANS_CACHE_KEY = 'retrain:pending_scan_ids';

    private function mlUrl(): string
    {
        return rtrim(config('services.ml.url'), '/');
    }

    private function retrainKey(): string
    {
        return (string) config('services.ml.retrain_key');
    }

    /**
     * Format a scan row into a compact candidate payload for the UI.
     */
    private function formatScanCandidate(Scan $scan): array
    {
        return [
            'id'             => $scan->id,
            'image_url'      => $scan->image_url,
            'image_path'     => $scan->image_path,
            'result_name'    => $scan->result_name,
            'confidence'     => round($scan->confidence_level ?? 0),
            'classification' => $scan->result_classification,
            'user'           => $scan->user?->name ?? 'Unknown',
            'scanned_at'     => $scan->created_at?->toDateString(),
            'retrained_at'   => $scan->retrained_at?->toDateString(),
        ];
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
     * Query consented scans eligible for retraining, optionally filtered by retrained state.
     */
    private function consentedScansQuery(bool $alreadyRetrained = false)
    {
        $query = Scan::whereHas('user', fn ($q) => $q->where('allow_data_training', true))
            ->whereIn('result_classification', ['edible', 'poisonous'])
            ->whereNotNull('image_path');

        return $alreadyRetrained
            ? $query->whereNotNull('retrained_at')
            : $query->whereNull('retrained_at');
    }

    /**
     * GET /api/admin/retrain/candidates
     *
     * Returns both ready-to-train scans (retrained_at IS NULL) and previously
     * retrained scans (retrained_at IS NOT NULL) so trained images stay inspectable
     * and can be restored to the queue if needed.
     */
    public function candidates(Request $request)
    {
        $readyBase     = $this->consentedScansQuery(false);
        $retrainedBase = $this->consentedScansQuery(true);

        $edible = (clone $readyBase)->where('result_classification', 'edible')
            ->with('user:id,name')
            ->latest()
            ->get()
            ->map(fn ($s) => $this->formatScanCandidate($s));

        $poisonous = (clone $readyBase)->where('result_classification', 'poisonous')
            ->with('user:id,name')
            ->latest()
            ->get()
            ->map(fn ($s) => $this->formatScanCandidate($s));

        $retrainedEdible = (clone $retrainedBase)->where('result_classification', 'edible')
            ->with('user:id,name')
            ->latest('retrained_at')
            ->get()
            ->map(fn ($s) => $this->formatScanCandidate($s));

        $retrainedPoisonous = (clone $retrainedBase)->where('result_classification', 'poisonous')
            ->with('user:id,name')
            ->latest('retrained_at')
            ->get()
            ->map(fn ($s) => $this->formatScanCandidate($s));

        return response()->json([
            'edible'              => $edible,
            'poisonous'           => $poisonous,
            'retrained_edible'    => $retrainedEdible,
            'retrained_poisonous' => $retrainedPoisonous,
            'summary'             => [
                'edible_count'    => $edible->count(),
                'poisonous_count' => $poisonous->count(),
                'total'           => $edible->count() + $poisonous->count(),
            ],
            'retrained_summary'   => [
                'edible_count'    => $retrainedEdible->count(),
                'poisonous_count' => $retrainedPoisonous->count(),
                'total'           => $retrainedEdible->count() + $retrainedPoisonous->count(),
            ],
        ]);
    }

    /**
     * POST /api/admin/retrain/restore
     *
     * Clears `retrained_at` on selected (or all) previously marked consented scans
     * so they return to the active training queue.
     */
    public function restore(Request $request)
    {
        $data = $request->validate([
            'scan_ids'   => 'sometimes|array',
            'scan_ids.*' => 'integer',
            'all'        => 'sometimes|boolean',
        ]);

        $query = $this->consentedScansQuery(true);

        if (! ($data['all'] ?? false)) {
            $ids = $data['scan_ids'] ?? [];
            if (empty($ids)) {
                return response()->json([
                    'message' => 'Select at least one scan image to restore.',
                ], 422);
            }
            $query->whereIn('id', $ids);
        }

        $restored = $query->update(['retrained_at' => null]);

        return response()->json([
            'message'  => $restored === 1
                ? '1 scan image restored to the training queue.'
                : "{$restored} scan images restored to the training queue.",
            'restored' => $restored,
        ]);
    }

    /**
     * POST /api/admin/retrain
     *
     * Collects consented scan images, logs a RetrainRun row, and streams them
     * to Flask's /retrain endpoint. Scans are only marked `retrained_at` once
     * training completes successfully (in status()).
     */
    public function trigger(Request $request)
    {
        $request->validate([
            'edible_ids'      => 'sometimes|array',
            'edible_ids.*'    => 'integer',
            'poisonous_ids'   => 'sometimes|array',
            'poisonous_ids.*' => 'integer',
        ]);

        $baseQuery = $this->consentedScansQuery(false);

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

        $hasRunsTable = Schema::hasTable('retrain_runs');
        $run = null;

        if ($hasRunsTable) {
            $run = RetrainRun::create([
                'triggered_by'  => $request->user()?->id,
                'status'        => 'running',
                'new_edible'    => $edibleCount,
                'new_poisonous' => $poisonousCount,
                'scan_ids'      => $sentScanIds,
                'started_at'    => now(),
            ]);
        } else {
            Cache::put(self::PENDING_SCANS_CACHE_KEY, $sentScanIds, now()->addHours(2));
        }

        try {
            $guzzle   = new \GuzzleHttp\Client(['timeout' => 30]);
            $response = $guzzle->post($this->mlUrl() . '/retrain', [
                'headers'   => ['X-Retrain-Key' => $this->retrainKey()],
                'multipart' => $multipart,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            $body = is_array($body) ? $body : [];

            return response()->json(array_merge($body, [
                'run_id'           => $run?->id,
                'edible_queued'    => $edibleCount,
                'poisonous_queued' => $poisonousCount,
            ]), $response->getStatusCode());

        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            Log::error('Retrain: Flask service offline', ['msg' => $e->getMessage()]);
            if ($run) {
                $run->update([
                    'status'      => 'failed',
                    'finished_at' => now(),
                    'error'       => 'ML service is offline.',
                ]);
            }
            return response()->json(['message' => 'ML service is offline.'], 503);

        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $statusCode = $e->getResponse()?->getStatusCode() ?? 500;
            $rawBody    = $e->getResponse()?->getBody()->getContents();
            $parsed     = $rawBody ? json_decode($rawBody, true) : null;
            $errMsg     = is_array($parsed) && !empty($parsed['message'])
                ? $parsed['message']
                : ('Retrain request failed: ' . $e->getMessage());

            Log::error('Retrain: HTTP error from ML service', ['status' => $statusCode, 'msg' => $errMsg]);

            if ($run) {
                if ($statusCode === 409) {
                    // Do not pollute history with a duplicate row if another job is already running
                    $run->delete();
                } else {
                    $run->update([
                        'status'      => 'failed',
                        'finished_at' => now(),
                        'error'       => $errMsg,
                    ]);
                }
            }

            return response()->json(['message' => $errMsg], $statusCode);

        } catch (\Exception $e) {
            Log::error('Retrain: unexpected error', ['msg' => $e->getMessage()]);
            if ($run) {
                $run->update([
                    'status'      => 'failed',
                    'finished_at' => now(),
                    'error'       => 'Retrain request failed: ' . $e->getMessage(),
                ]);
            }
            return response()->json(['message' => 'Retrain request failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Apply a completed Flask retrain result onto our DB run record and stamp
     * `retrained_at` on its scans only after training actually succeeds.
     */
    private function applyDoneState(RetrainRun $run, array $flaskData): bool
    {
        $current      = $flaskData['current'] ?? [];
        $flaskHistory = $flaskData['history'] ?? [];
        $last         = $current['last_result'] ?? ($flaskHistory[0] ?? []);

        $finishedAt = !empty($current['finished_at'])
            ? Carbon::parse($current['finished_at'])
            : (!empty($last['finished_at']) ? Carbon::parse($last['finished_at']) : now());

        $run->update([
            'status'             => 'done',
            'finished_at'        => $finishedAt,
            'total_edible'       => $last['total_edible'] ?? null,
            'total_poisonous'    => $last['total_poisonous'] ?? null,
            'total_not_mushroom' => $last['total_not_mushroom'] ?? null,
            'total_images'       => $last['total_images'] ?? null,
            'train_accuracy'     => $last['train_accuracy'] ?? null,
            'val_accuracy'       => $last['val_accuracy'] ?? null,
            'error'              => null,
        ]);

        if (! $run->scans_marked_retrained && ! empty($run->scan_ids)) {
            Scan::whereIn('id', $run->scan_ids)->update(['retrained_at' => now()]);
            $run->update(['scans_marked_retrained' => true]);
            return true;
        }

        return false;
    }

    /**
     * Merge persisted PostgreSQL history with any older Flask log entries.
     */
    private function buildHistory(array $flaskHistory): array
    {
        if (! Schema::hasTable('retrain_runs')) {
            return $flaskHistory;
        }

        $dbRuns = RetrainRun::latest('id')->take(20)->get()->map->toHistoryArray()->all();

        $knownTimestamps = array_filter(array_map(
            fn ($item) => isset($item['started_at']) ? strtotime((string) $item['started_at']) : null,
            $dbRuns
        ));

        foreach ($flaskHistory as $entry) {
            if (! is_array($entry) || empty($entry['started_at'])) {
                continue;
            }
            $ts = strtotime((string) $entry['started_at']);
            $matchesExisting = false;
            foreach ($knownTimestamps as $knownTs) {
                if ($ts !== false && abs($ts - $knownTs) <= 90) {
                    $matchesExisting = true;
                    break;
                }
            }
            if (! $matchesExisting) {
                $dbRuns[] = $entry;
            }
        }

        usort($dbRuns, function ($a, $b) {
            return strcmp((string) ($b['started_at'] ?? ''), (string) ($a['started_at'] ?? ''));
        });

        return array_slice($dbRuns, 0, 20);
    }

    /**
     * GET /api/admin/retrain/status
     *
     * Syncs Flask's /retrain/status into persistent DB run history and stamps
     * `retrained_at` on scans only once retraining succeeds.
     */
    public function status()
    {
        $scansUpdated = false;

        try {
            $response = Http::timeout(10)
                ->withHeaders(['X-Retrain-Key' => $this->retrainKey()])
                ->get($this->mlUrl() . '/retrain/status');

            if (! $response->successful()) {
                return response()->json([
                    'current' => [
                        'state' => 'unknown',
                        'error' => $response->json('message') ?? ('ML service returned HTTP ' . $response->status() . '.'),
                    ],
                    'history'       => $this->buildHistory([]),
                    'scans_updated' => false,
                ], 200);
            }

            $flaskData    = $response->json() ?? [];
            $current      = $flaskData['current'] ?? ['state' => 'idle'];
            $flaskHistory = is_array($flaskData['history'] ?? null) ? $flaskData['history'] : [];
            $state        = $current['state'] ?? 'idle';

            if (Schema::hasTable('retrain_runs')) {
                $runningRun = RetrainRun::where('status', 'running')->latest('id')->first();

                if ($runningRun) {
                    if ($state === 'done') {
                        $scansUpdated = $this->applyDoneState($runningRun, $flaskData);
                    } elseif ($state === 'failed') {
                        $runningRun->update([
                            'status'      => 'failed',
                            'finished_at' => !empty($current['finished_at']) ? Carbon::parse($current['finished_at']) : now(),
                            'error'       => $current['error'] ?? 'Retraining failed.',
                        ]);
                    } elseif ($state === 'idle') {
                        $latestFlask = $flaskHistory[0] ?? null;
                        $flaskTs     = is_array($latestFlask) && !empty($latestFlask['started_at'])
                            ? strtotime((string) $latestFlask['started_at'])
                            : false;

                        if (
                            is_array($latestFlask)
                            && ($latestFlask['status'] ?? null) === 'done'
                            && $flaskTs !== false
                            && $runningRun->started_at
                            && $flaskTs >= $runningRun->started_at->timestamp - 60
                        ) {
                            $scansUpdated = $this->applyDoneState($runningRun, $flaskData);
                        } elseif ($runningRun->started_at && $runningRun->started_at->lt(now()->subSeconds(10))) {
                            $runningRun->update([
                                'status'      => 'failed',
                                'finished_at' => now(),
                                'error'       => 'ML service restarted or ran out of memory during retraining.',
                            ]);
                        }
                    }
                }

                // If Flask restarted (idle) right after our latest DB run failed/succeeded, reflect that accurately
                $latestDb = RetrainRun::latest('id')->first();
                if ($state === 'idle' && $latestDb && $latestDb->status === 'failed' && $latestDb->updated_at?->gt(now()->subMinutes(15))) {
                    $current = [
                        'state'       => 'failed',
                        'started_at'  => $latestDb->started_at?->toIso8601ZuluString(),
                        'finished_at' => $latestDb->finished_at?->toIso8601ZuluString(),
                        'error'       => $latestDb->error,
                        'last_result' => null,
                    ];
                }
            } else {
                // Pre-migration cache fallback so failed runs never hide scans either
                if ($state === 'done') {
                    $pendingIds = Cache::pull(self::PENDING_SCANS_CACHE_KEY, []);
                    if (! empty($pendingIds)) {
                        Scan::whereIn('id', $pendingIds)->update(['retrained_at' => now()]);
                        $scansUpdated = true;
                    }
                } elseif ($state === 'failed') {
                    Cache::forget(self::PENDING_SCANS_CACHE_KEY);
                }
            }

            return response()->json([
                'current'       => $current,
                'history'       => $this->buildHistory($flaskHistory),
                'scans_updated' => $scansUpdated,
            ], $response->status());

        } catch (\Exception $e) {
            Log::error('Retrain status check failed', ['msg' => $e->getMessage()]);

            return response()->json([
                'current'       => [
                    'state' => 'unknown',
                    'error' => 'ML service unreachable.',
                ],
                'history'       => $this->buildHistory([]),
                'scans_updated' => false,
            ], 200);
        }
    }

    /**
     * POST /api/admin/retrain/refresh
     * Forces the ML service to reload & warm up its Stage-1 and Stage-2 models
     * so new scans run against the latest weights without a cold-start failure.
     */
    public function refreshMl()
    {
        try {
            $response = Http::timeout(45)
                ->withHeaders(['X-Retrain-Key' => $this->retrainKey()])
                ->post($this->mlUrl() . '/refresh');

            $body = $response->json();
            return response()->json(
                is_array($body) ? $body : ['message' => 'ML refresh completed.'],
                $response->status()
            );
        } catch (\Throwable $e) {
            Log::error('ML refresh failed', ['msg' => $e->getMessage()]);
            return response()->json([
                'message' => 'Could not reach ML service to refresh models. It may still be waking up on Render.',
            ], 503);
        }
    }
}
