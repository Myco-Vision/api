<?php

namespace App\Http\Controllers;

use App\Models\RetrainRun;
use App\Models\Scan;
use App\Models\Species;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

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
            $host = parse_url($imagePath, PHP_URL_HOST);
            if ($host && ! in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
                try {
                    $res = Http::timeout(4)->connectTimeout(2)->get($imagePath);
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
        $publicBaseUrl = config('filesystems.disks.public.url')
            ?: config('filesystems.disks.s3.url')
            ?: env('AWS_URL')
            ?: 'https://jxraqiaqkyimzopzgeeo.supabase.co/storage/v1/object/public/mycovision';

        if ($publicBaseUrl) {
            $host = parse_url($publicBaseUrl, PHP_URL_HOST);
            if ($host && ! in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
                $remoteUrl = rtrim((string) $publicBaseUrl, '/') . '/' . ltrim($imagePath, '/');
                try {
                    $res = Http::timeout(6)->get($remoteUrl);
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
        }

        // 4. Fallback: direct storage_path check on local disk if public disk path differs
        $localPath = storage_path('app/public/' . ltrim($imagePath, '/'));
        if (file_exists($localPath)) {
            $contents = @file_get_contents($localPath);
            if ($contents !== false && $contents !== '') {
                return [
                    'contents' => $contents,
                    'filename' => basename($imagePath),
                ];
            }
        }

        // 5. Fallback: try direct HTTP GET if $scan->image_url is a full remote URL (non-localhost)
        if ($scan->image_url && (str_starts_with($scan->image_url, 'http://') || str_starts_with($scan->image_url, 'https://'))) {
            $host = parse_url($scan->image_url, PHP_URL_HOST);
            if ($host && ! in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)) {
                try {
                    $res = Http::timeout(4)->connectTimeout(2)->get($scan->image_url);
                    if ($res->successful() && $res->body() !== '') {
                        return [
                            'contents' => $res->body(),
                            'filename' => basename(parse_url($scan->image_url, PHP_URL_PATH) ?: $imagePath),
                        ];
                    }
                } catch (\Throwable $e) {
                    // silent
                }
            }
        }

        Log::warning("Retrain: image file missing for scan #{$scan->id}: {$imagePath}");
        return null;
    }

    /**
     * Compute 64-bit difference hash (dHash) for an image.
     */
    private function computeDHash(?string $bytes): ?string
    {
        if (! extension_loaded('gd') || ! $bytes) {
            return null;
        }

        $img = @imagecreatefromstring($bytes);
        if (! $img) {
            return null;
        }

        $w = 9;
        $h = 8;
        $small = imagecreatetruecolor($w, $h);
        imagecopyresampled($small, $img, 0, 0, 0, 0, $w, $h, imagesx($img), imagesy($img));
        imagefilter($small, IMG_FILTER_GRAYSCALE);

        $bits = '';
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w - 1; $x++) {
                $left  = imagecolorat($small, $x, $y) & 0xFF;
                $right = imagecolorat($small, $x + 1, $y) & 0xFF;
                $bits .= ($left > $right) ? '1' : '0';
            }
        }

        imagedestroy($img);
        imagedestroy($small);

        return $bits;
    }

    /**
     * Calculate Hamming distance between two 64-bit strings.
     */
    private function hammingDistance(string $b1, string $b2): int
    {
        $len1 = strlen($b1);
        $len2 = strlen($b2);
        if ($len1 !== 64 || $len2 !== 64) {
            return 64;
        }
        $dist = 0;
        for ($i = 0; $i < 64; $i++) {
            if ($b1[$i] !== $b2[$i]) {
                $dist++;
            }
        }
        return $dist;
    }

    /**
     * Compute/retrieve cached image fingerprints (MD5, SHA1, dHash, size).
     */
    private function computeScanImageHashes(Scan $scan): ?array
    {
        $cacheKey = "scan_hashes_{$scan->id}_" . ($scan->updated_at?->timestamp ?? 0);
        $cached = Cache::remember($cacheKey, now()->addDays(7), function () use ($scan) {
            $data = $this->readScanImageContents($scan);
            if (! $data || empty($data['contents'])) {
                return ['missing' => true];
            }
            $bytes = $data['contents'];
            return [
                'md5'   => md5($bytes),
                'sha1'  => sha1($bytes),
                'size'  => strlen($bytes),
                'dhash' => $this->computeDHash($bytes),
            ];
        });

        return (is_array($cached) && empty($cached['missing'])) ? $cached : null;
    }

    /**
     * Query consented scans eligible for retraining, optionally filtered by retrained state.
     */
    private function consentedScansQuery(bool $alreadyRetrained = false)
    {
        $query = Scan::whereIn('result_classification', ['edible', 'poisonous'])
            ->whereNotNull('image_path')
            ->where(function ($q) {
                // Three pathways for consent / retraining dataset eligibility:
                // 1. Approved crowdsourced contributions from research-consenting users
                $q->where(function ($sub) {
                    $sub->whereHas('contribution', fn ($c) => $c->where('annotation_status', 'approved'))
                        ->whereHas('user', fn ($u) => $u->where('allow_data_training', true));
                })
                // 2. Admin/super_admin baseline seeds and direct computer uploads
                ->orWhere(function ($sub) {
                    $sub->whereDoesntHave('contribution')
                        ->whereHas('user', fn ($u) => $u->whereIn('role', ['super_admin', 'admin']));
                })
                // 3. Regular user scans in folders with allow_ai_training=true
                ->orWhere(function ($sub) {
                    $sub->whereDoesntHave('contribution')
                        ->whereHas('collectionItems.folder', fn ($f) => $f->where('allow_ai_training', true));
                });
            });

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
     * POST /api/admin/retrain/upload
     *
     * Upload mushroom images directly from the admin's computer into the retraining dataset.
     */
    public function upload(Request $request)
    {
        $data = $request->validate([
            'images'         => 'required|array|min:1',
            'images.*'       => 'required|file|image|mimes:jpeg,png,jpg,webp|max:15360',
            'classification' => 'required|string|in:edible,poisonous',
            'species_name'   => 'nullable|string|max:255',
            'species_id'     => 'nullable|integer|exists:species,id',
        ]);

        $speciesName = trim((string) ($data['species_name'] ?? ''));
        if (empty($speciesName) && !empty($data['species_id'])) {
            $speciesName = Species::find($data['species_id'])?->name ?: '';
        }
        if (empty($speciesName)) {
            $speciesName = 'Admin Uploaded ' . ucfirst($data['classification']);
        }

        $created = [];
        $files = $request->file('images');

        foreach ($files as $file) {
            $storedPath = $file->store('scans/admin', 'public');
            $imageHash = @md5_file($file->getRealPath()) ?: null;

            $scan = Scan::create([
                'user_id'               => $request->user()->id,
                'species_id'            => $data['species_id'] ?? null,
                'image_path'            => $storedPath,
                'image_hash'            => $imageHash,
                'result_name'           => $speciesName,
                'result_classification' => $data['classification'],
                'confidence_level'      => 100.0,
                'retrained_at'          => null,
            ]);

            $created[] = $this->formatScanCandidate($scan->fresh(['user']));
        }

        return response()->json([
            'message'        => count($created) . " {$data['classification']} image(s) uploaded successfully to the retraining dataset.",
            'uploaded_count' => count($created),
            'classification' => $data['classification'],
            'species_name'   => $speciesName,
            'scans'          => $created,
        ], 201);
    }

    /**
     * GET /api/admin/retrain/export
     *
     * Export retraining mushroom images into a downloadable ZIP archive with metadata CSV.
     */
    public function export(Request $request)
    {
        $classification = $request->query('classification', 'all'); // 'edible' | 'poisonous' | 'all'
        $scope          = $request->query('scope', 'ready');         // 'ready' | 'trained' | 'all'
        $idsParam       = $request->query('ids');

        $query = Scan::whereNotNull('image_path')
            ->whereIn('result_classification', ['edible', 'poisonous'])
            ->with(['user:id,name,role']);

        if (!empty($idsParam)) {
            $ids = array_filter(array_map('intval', explode(',', (string) $idsParam)));
            $query->whereIn('id', $ids);
        } else {
            if ($scope === 'all') {
                $query = Scan::whereIn('result_classification', ['edible', 'poisonous'])
                    ->whereNotNull('image_path')
                    ->where(function ($q) {
                        $q->where(function ($sub) {
                            $sub->whereHas('contribution', fn ($c) => $c->where('annotation_status', 'approved'))
                                ->whereHas('user', fn ($u) => $u->where('allow_data_training', true));
                        })
                        ->orWhere(function ($sub) {
                            $sub->whereDoesntHave('contribution')
                                ->whereHas('user', fn ($u) => $u->whereIn('role', ['super_admin', 'admin']));
                        })
                        ->orWhere(function ($sub) {
                            $sub->whereDoesntHave('contribution')
                                ->whereHas('collectionItems.folder', fn ($f) => $f->where('allow_ai_training', true));
                        });
                    });
            } else {
                $query = $this->consentedScansQuery($scope === 'trained');
            }

            if ($classification !== 'all' && in_array($classification, ['edible', 'poisonous'], true)) {
                $query->where('result_classification', $classification);
            }
        }

        $scans = $query->latest('id')->get();

        if ($scans->isEmpty()) {
            return response()->json([
                'message' => 'No retraining mushroom images found matching the export filter.',
            ], 422);
        }

        $tmpDir = sys_get_temp_dir();
        $classLabel = in_array($classification, ['edible', 'poisonous'], true) ? $classification : 'all';
        $zipFilename = 'mycovision_retrain_' . $classLabel . '_images_' . now()->format('Ymd_His') . '.zip';
        $zipPath = $tmpDir . DIRECTORY_SEPARATOR . $zipFilename;

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return response()->json(['message' => 'Could not create ZIP archive on server.'], 500);
        }

        $csvLines = [];
        $csvLines[] = "scan_id,classification,species_name,confidence,file_path_in_zip,uploaded_by,source,created_at";

        $exportedCount = 0;

        foreach ($scans as $scan) {
            $fileData = $this->readScanImageContents($scan);
            if (!$fileData || empty($fileData['contents'])) {
                continue;
            }

            $clsFolder = strtolower($scan->result_classification ?? 'unknown');
            $speciesFolder = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $scan->result_name ?: 'unnamed_species');
            $safeFilename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $fileData['filename'] ?: "scan_{$scan->id}.jpg");
            $inZipPath = "{$clsFolder}/{$speciesFolder}/scan_{$scan->id}_{$safeFilename}";

            $zip->addFromString($inZipPath, $fileData['contents']);

            $safeSpecies = str_replace('"', '""', $scan->result_name ?: '');
            $safeUser = str_replace('"', '""', $scan->user?->name ?? 'Admin');
            $source = $scan->user?->role === 'forager' ? 'forager_scan' : 'admin_import';

            $csvLines[] = sprintf(
                '%d,"%s","%s",%.2f,"%s","%s","%s","%s"',
                $scan->id,
                $clsFolder,
                $safeSpecies,
                $scan->confidence_level ?? 0,
                $inZipPath,
                $safeUser,
                $source,
                $scan->created_at?->toIso8601String() ?? ''
            );

            $exportedCount++;
        }

        $zip->addFromString('metadata.csv', implode("\n", $csvLines));
        $zip->close();

        if ($exportedCount === 0 || !file_exists($zipPath)) {
            @unlink($zipPath);
            return response()->json([
                'message' => 'None of the selected mushroom images could be read from storage.',
            ], 422);
        }

        return response()->download($zipPath, $zipFilename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
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
     * POST /api/admin/retrain/queue-scan
     *
     * Explicitly approves and queues a consented candidate scan from the
     * Candidate Scans tab directly into the AI Model Retraining dataset.
     */
    public function queueCandidateScan(Request $request)
    {
        $data = $request->validate([
            'scan_id'        => 'required|integer|exists:scans,id',
            'classification' => 'nullable|in:edible,poisonous',
            'species_name'   => 'nullable|string|max:255',
        ]);

        $scan = Scan::with('user')->findOrFail($data['scan_id']);

        $cls = $data['classification']
            ?? (in_array($scan->result_classification, ['edible', 'poisonous'], true) ? $scan->result_classification : 'edible');
        $speciesName = $data['species_name'] ?? ($scan->result_name ?: 'Verified Mushroom');

        $scan->update([
            'result_name'           => $speciesName,
            'result_classification' => $cls,
            'retrained_at'          => null,
        ]);

        $contribution = \App\Models\ScanContribution::where('scan_id', $scan->id)->first();
        if ($contribution) {
            $previousStatus = $contribution->annotation_status;
            $points = (int) $contribution->points_awarded;

            $contribution->update([
                'verified_species'  => $speciesName,
                'annotation_status' => 'approved',
            ]);

            if ($previousStatus === 'pending' && $points > 0 && $contribution->user_id) {
                $contribUser = \App\Models\User::find($contribution->user_id);
                if ($contribUser) {
                    $deduct = min((int) $contribUser->pending_points, $points);
                    $contribUser->decrement('pending_points', $deduct);
                    $contribUser->increment('reward_points', $points);

                    \App\Models\PointTransaction::create([
                        'user_id'        => $contribUser->id,
                        'points'         => $points,
                        'type'           => 'annotation_approved',
                        'description'    => "AI data contribution verified and queued for retraining ({$speciesName})",
                        'reference_type' => 'scan_contribution',
                        'reference_id'   => $contribution->id,
                    ]);
                }
            }
        } elseif ($scan->user_id) {
            $rawConf = (float) ($scan->confidence_level ?? 0);
            $confScore = $rawConf > 1.0 ? ($rawConf / 100.0) : $rawConf;
            \App\Models\ScanContribution::create([
                'scan_id'                 => $scan->id,
                'user_id'                 => $scan->user_id,
                'image_path'              => $scan->image_path,
                'predicted_species'       => $speciesName,
                'verified_species'        => $speciesName,
                'confidence_score'        => round($confScore, 4),
                'annotation_status'       => 'approved',
                'points_awarded'          => 0,
                'is_new_species_for_user' => false,
            ]);
        }

        return response()->json([
            'message' => "Scan #{$scan->id} ({$speciesName}) added to AI Model Retraining queue.",
            'scan'    => $scan->fresh(['user', 'species', 'contribution']),
        ]);
    }

    /**
     * GET /api/admin/retrain/duplicates
     *
     * Reviews consented candidate scan images, computes exact (MD5) and perceptual (dHash)
     * image fingerprints, and clusters duplicates into sets. Also identifies label conflicts
     * (the same image classified as both edible and poisonous).
     */
    public function duplicates(Request $request)
    {
        $scope = $request->query('scope', 'ready'); // 'ready' (default) | 'all'

        $query = $this->consentedScansQuery($scope === 'all')
            ->with(['user:id,name,role', 'contribution']);

        $scans = $query->orderBy('created_at', 'asc')->get();

        if ($scans->isEmpty()) {
            return response()->json([
                'total_scans_analyzed'     => 0,
                'duplicate_groups_count'   => 0,
                'redundant_scans_count'    => 0,
                'conflict_groups_count'    => 0,
                'groups'                   => [],
                'recommended_unselect_ids' => [],
                'duplicate_scan_ids_map'   => new \stdClass(),
            ]);
        }

        // 1. Compute/retrieve fingerprints for each scan
        $fingerprints = [];
        $validScans   = [];

        foreach ($scans as $scan) {
            $hashData = $this->computeScanImageHashes($scan);
            if (! $hashData) {
                // Even if image content cannot be read over network, retain scan for metadata duplicate matching
                $hashData = [
                    'md5'   => $scan->image_hash ?: "scan_hash_{$scan->id}",
                    'sha1'  => '',
                    'size'  => 0,
                    'dhash' => null,
                ];
            }
            $validScans[$scan->id]   = $scan;
            $fingerprints[$scan->id] = $hashData;
        }

        if (count($validScans) < 2) {
            return response()->json([
                'total_scans_analyzed'     => count($validScans),
                'duplicate_groups_count'   => 0,
                'redundant_scans_count'    => 0,
                'conflict_groups_count'    => 0,
                'groups'                   => [],
                'recommended_unselect_ids' => [],
                'duplicate_scan_ids_map'   => new \stdClass(),
            ]);
        }

        // 2. Disjoint-Set / Union-Find clustering
        $parent = [];
        $pairwiseMatches = [];

        foreach ($validScans as $id => $_) {
            $parent[$id] = $id;
        }

        $find = function ($i) use (&$find, &$parent) {
            if ($parent[$i] === $i) {
                return $i;
            }
            return $parent[$i] = $find($parent[$i]);
        };

        $union = function ($i, $j) use (&$find, &$parent) {
            $rootI = $find($i);
            $rootJ = $find($j);
            if ($rootI !== $rootJ) {
                $parent[$rootI] = $rootJ;
            }
        };

        $scanIds = array_keys($validScans);
        $count   = count($scanIds);

        for ($i = 0; $i < $count; $i++) {
            $id1 = $scanIds[$i];
            $fp1 = $fingerprints[$id1];
            $s1  = $validScans[$id1];

            for ($j = $i + 1; $j < $count; $j++) {
                $id2 = $scanIds[$j];
                $fp2 = $fingerprints[$id2];
                $s2  = $validScans[$id2];

                $isExact = (! empty($fp1['md5']) && ! empty($fp2['md5']) && $fp1['md5'] === $fp2['md5'] && ! str_starts_with($fp1['md5'], 'scan_hash_'));
                $isHashExact = (! empty($s1->image_hash) && ! empty($s2->image_hash) && $s1->image_hash === $s2->image_hash);

                $distance = 64;
                if (! $isExact && ! empty($fp1['dhash']) && ! empty($fp2['dhash'])) {
                    $distance = $this->hammingDistance($fp1['dhash'], $fp2['dhash']);
                }

                $isSameUserAndSpecimen = ($s1->user_id && $s1->user_id === $s2->user_id) && (
                    ($s1->species_id && $s1->species_id === $s2->species_id) ||
                    (strtolower(trim((string) $s1->result_name)) === strtolower(trim((string) $s2->result_name)))
                );

                if ($isExact || $isHashExact) {
                    $union($id1, $id2);
                    $pairKey = min($id1, $id2) . '_' . max($id1, $id2);
                    $pairwiseMatches[$pairKey] = ['type' => 'exact', 'distance' => 0];
                } elseif ($distance <= 5) {
                    $union($id1, $id2);
                    $pairKey = min($id1, $id2) . '_' . max($id1, $id2);
                    $pairwiseMatches[$pairKey] = ['type' => 'perceptual', 'distance' => $distance];
                } elseif ($isSameUserAndSpecimen) {
                    $union($id1, $id2);
                    $pairKey = min($id1, $id2) . '_' . max($id1, $id2);
                    $pairwiseMatches[$pairKey] = ['type' => 'specimen', 'distance' => 8];
                }
            }
        }

        // 3. Collect groups
        $rawGroups = [];
        foreach ($scanIds as $id) {
            $root = $find($id);
            $rawGroups[$root][] = $id;
        }

        $groups                 = [];
        $recommendedUnselectIds = [];
        $duplicateScanIdsMap    = [];
        $conflictGroupsCount    = 0;
        $groupIdCounter         = 1;

        foreach ($rawGroups as $root => $memberIds) {
            if (count($memberIds) < 2) {
                continue;
            }

            // Retrieve and sort member scans:
            // Prefer: 1. Highest confidence_level desc, 2. Oldest created_at asc
            $memberScans = [];
            foreach ($memberIds as $mId) {
                $memberScans[] = $validScans[$mId];
            }

            usort($memberScans, function ($a, $b) {
                if ($b->confidence_level !== $a->confidence_level) {
                    return $b->confidence_level <=> $a->confidence_level;
                }
                return $a->created_at <=> $b->created_at;
            });

            // Determine if exact, specimen, or perceptual
            $hasPerceptual = false;
            $hasSpecimen   = false;
            $maxDistance   = 0;
            for ($x = 0; $x < count($memberIds); $x++) {
                for ($y = $x + 1; $y < count($memberIds); $y++) {
                    $pKey = min($memberIds[$x], $memberIds[$y]) . '_' . max($memberIds[$x], $memberIds[$y]);
                    if (isset($pairwiseMatches[$pKey])) {
                        if ($pairwiseMatches[$pKey]['type'] === 'specimen') {
                            $hasSpecimen = true;
                        } elseif ($pairwiseMatches[$pKey]['type'] === 'perceptual') {
                            $hasPerceptual = true;
                            $maxDistance   = max($maxDistance, $pairwiseMatches[$pKey]['distance']);
                        }
                    }
                }
            }

            $matchType  = $hasPerceptual ? 'perceptual' : ($hasSpecimen ? 'specimen' : 'exact');
            $similarity = $hasPerceptual
                ? round((1 - ($maxDistance / 64)) * 100, 1)
                : ($hasSpecimen ? 92.0 : 100.0);

            // Check for classification conflicts
            $classifications = array_unique(array_map(fn ($s) => $s->result_classification, $memberScans));
            $hasConflict     = count($classifications) > 1;
            if ($hasConflict) {
                $conflictGroupsCount++;
                $groupClass = 'conflict';
            } else {
                $groupClass = reset($classifications);
            }

            $primaryScan      = $memberScans[0];
            $groupUnselectIds = [];
            $formattedScans   = [];

            foreach ($memberScans as $idx => $s) {
                $isPrimary = ($idx === 0);
                if (! $isPrimary) {
                    $groupUnselectIds[]       = $s->id;
                    $recommendedUnselectIds[] = $s->id;
                }

                $duplicateScanIdsMap[$s->id] = [
                    'group_id'     => $groupIdCounter,
                    'is_primary'   => $isPrimary,
                    'match_type'   => $matchType,
                    'similarity'   => $similarity,
                    'has_conflict' => $hasConflict,
                ];

                $formattedScans[] = [
                    'id'             => $s->id,
                    'is_primary'     => $isPrimary,
                    'image_url'      => $s->image_url,
                    'image_path'     => $s->image_path,
                    'result_name'    => $s->result_name,
                    'confidence'     => round($s->confidence_level * 100, 1),
                    'classification' => $s->result_classification,
                    'user'           => $s->user?->name ?? 'Unknown',
                    'scanned_at'     => $s->created_at?->toDateString(),
                    'retrained_at'   => $s->retrained_at?->toDateString(),
                    'file_size'      => $fingerprints[$s->id]['size'] ?? null,
                ];
            }

            $groups[] = [
                'group_id'                 => $groupIdCounter++,
                'match_type'               => $matchType,
                'match_types'              => [$matchType],
                'similarity'               => $similarity,
                'has_conflict'             => $hasConflict,
                'classification'           => $groupClass,
                'classifications'          => array_values($classifications),
                'total_count'              => count($memberScans),
                'primary_scan_id'          => $primaryScan->id,
                'recommended_unselect_ids' => $groupUnselectIds,
                'scans'                    => $formattedScans,
            ];
        }

        $redundantCount = count($recommendedUnselectIds);

        return response()->json([
            'total_scans_analyzed'     => count($validScans),
            'duplicate_groups_count'   => count($groups),
            'redundant_scans_count'    => $redundantCount,
            'conflict_groups_count'    => $conflictGroupsCount,
            'groups'                   => $groups,
            'recommended_unselect_ids' => $recommendedUnselectIds,
            'duplicate_scan_ids_map'   => (object) $duplicateScanIdsMap,
        ]);
    }

    /**
     * POST /api/admin/retrain/exclude
     *
     * Mark selected duplicate or unwanted scans as excluded/retrained
     * so they are moved out of the active training queue without being trained.
     */
    public function exclude(Request $request)
    {
        $data = $request->validate([
            'scan_ids'   => 'required|array|min:1',
            'scan_ids.*' => 'integer',
        ]);

        $count = Scan::whereIn('id', $data['scan_ids'])
            ->whereNull('retrained_at')
            ->update(['retrained_at' => now()]);

        return response()->json([
            'message'  => "{$count} scan image(s) excluded from the active training queue.",
            'excluded' => $count,
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

        $scans = $baseQuery->with('contribution')->get();

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

            // Read from human-verified species if available, eliminating label noise
            $targetClassification = $scan->result_classification;
            $verifiedSpecies = $scan->contribution?->verified_species;

            if ($verifiedSpecies) {
                $matchedClass = \App\Models\Species::where('scientific_name', $verifiedSpecies)
                    ->orWhere('name', $verifiedSpecies)
                    ->value('classification');
                if ($matchedClass && in_array(strtolower($matchedClass), ['edible', 'poisonous'], true)) {
                    $targetClassification = strtolower($matchedClass);
                } elseif (in_array(strtolower($verifiedSpecies), ['edible', 'poisonous'], true)) {
                    $targetClassification = strtolower($verifiedSpecies);
                }
            }

            $sentScanIds[] = $scan->id;
            $multipart[] = [
                'name'     => 'images[]',
                'contents' => $fileData['contents'],
                'filename' => $fileData['filename'],
            ];
            $multipart[] = [
                'name'     => 'labels[]',
                'contents' => $targetClassification,
            ];
            $multipart[] = [
                'name'     => 'annotation_statuses[]',
                'contents' => $scan->contribution?->annotation_status ?? 'approved',
            ];
            $multipart[] = [
                'name'     => 'verified_species[]',
                'contents' => $verifiedSpecies ?? '',
            ];

            if ($targetClassification === 'edible') {
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

            // Award retrain bonus points to contributors
            $rewardService = new \App\Services\RewardService();
            $retrainedScans = Scan::whereIn('id', $sentScanIds)->get();
            foreach ($retrainedScans as $retrainedScan) {
                $rewardService->awardForRetrain($retrainedScan);
            }

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
    private function buildHistory(array $flaskHistory, bool $archived = false): array
    {
        if (! Schema::hasTable('retrain_runs')) {
            return $archived ? [] : $flaskHistory;
        }

        $query = RetrainRun::with('triggeredBy:id,name')->latest('id');
        if (Schema::hasColumn('retrain_runs', 'is_archived')) {
            $query = $archived ? $query->archived() : $query->active();
        } elseif ($archived) {
            return [];
        }

        $dbRuns = $query->take(50)->get()->map->toHistoryArray()->all();

        if ($archived) {
            return $dbRuns;
        }

        // Check against ALL existing database runs (active and archived) to prevent re-importing archived runs
        $allDbRuns = RetrainRun::select(['id', 'started_at', 'status', 'is_archived'])->get();
        $knownTimestamps = array_filter(array_map(
            fn ($item) => $item->started_at ? $item->started_at->timestamp : null,
            $allDbRuns->all()
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
                // If a Flask log entry says "running" but started over 15 minutes ago, it's stale/interrupted
                $entryStatus = $entry['status'] ?? 'done';
                if ($entryStatus === 'running' && $ts !== false && $ts < (time() - 900)) {
                    $entryStatus = 'failed';
                    $entryError  = 'Retraining timed out or was interrupted.';
                } else {
                    $entryError = $entry['error'] ?? null;
                }

                try {
                    $newRun = RetrainRun::create([
                        'status'          => $entryStatus,
                        'train_accuracy'  => $entry['train_accuracy'] ?? null,
                        'val_accuracy'    => $entry['val_accuracy'] ?? null,
                        'total_images'    => $entry['total_images'] ?? null,
                        'total_edible'    => $entry['total_edible'] ?? null,
                        'total_poisonous' => $entry['total_poisonous'] ?? null,
                        'started_at'      => ! empty($entry['started_at']) ? Carbon::parse($entry['started_at']) : null,
                        'finished_at'     => ! empty($entry['finished_at']) ? Carbon::parse($entry['finished_at']) : null,
                        'error'           => $entryError,
                        'is_archived'     => false,
                    ]);
                    $dbRuns[] = $newRun->toHistoryArray();
                } catch (\Throwable) {
                    $dbRuns[] = $entry;
                }
            }
        }

        usort($dbRuns, function ($a, $b) {
            return strcmp((string) ($b['started_at'] ?? ''), (string) ($a['started_at'] ?? ''));
        });

        return array_slice($dbRuns, 0, 50);
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

        // Debounce / cache idle status for 45s when no retrain run is active to protect Modal credits
        $force = (bool) request()->query('force', false);
        $hasActiveRun = Schema::hasTable('retrain_runs') && RetrainRun::where('status', 'running')->exists();

        if (! $force && ! $hasActiveRun) {
            $cached = Cache::get('retrain:ml_idle_status');
            if ($cached && is_array($cached)) {
                return response()->json($cached);
            }
        }

        try {
            $response = Http::timeout(30)
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
                // Auto-expire any abandoned runs that have been "running" for over 15 minutes
                RetrainRun::where('status', 'running')
                    ->where(function ($q) {
                        $q->where('started_at', '<', now()->subMinutes(15))
                          ->orWhere('created_at', '<', now()->subMinutes(30));
                    })
                    ->update([
                        'status'      => 'failed',
                        'finished_at' => now(),
                        'error'       => 'Retraining timed out or was interrupted.',
                    ]);

                $runningRun = RetrainRun::where('status', 'running')->latest('id')->first();
                if (! $runningRun) {
                    // Check if there is an unfinalized run from the last 30 minutes
                    $runningRun = RetrainRun::where('scans_marked_retrained', false)
                        ->where('created_at', '>=', now()->subMinutes(30))
                        ->latest('id')
                        ->first();
                }

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
                        } elseif ($runningRun->status === 'running' && $runningRun->started_at && $runningRun->started_at->lt(now()->subMinutes(10))) {
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

            $payload = [
                'current'          => $current,
                'history'          => $this->buildHistory($flaskHistory, false),
                'archived_history' => $this->buildHistory([], true),
                'scans_updated'    => $scansUpdated,
            ];

            if ($state === 'idle' && ! $hasActiveRun) {
                Cache::put('retrain:ml_idle_status', $payload, 45);
            }

            return response()->json($payload, $response->status());

        } catch (\Exception $e) {
            Log::error('Retrain status check failed', ['msg' => $e->getMessage()]);

            return response()->json([
                'current'       => [
                    'state' => 'unknown',
                    'error' => 'ML service unreachable.',
                ],
                'history'          => $this->buildHistory([], false),
                'archived_history' => $this->buildHistory([], true),
                'scans_updated'    => false,
            ], 200);
        }
    }

    /**
     * POST /api/admin/retrain/history/archive
     * Archive one or more retrain history runs
     */
    public function archiveHistory(Request $request)
    {
        $ids = $this->extractHistoryIds($request);
        if (empty($ids)) {
            return response()->json(['message' => 'No valid history run IDs provided.'], 422);
        }

        $count = RetrainRun::whereIn('id', $ids)->update([
            'is_archived' => true,
            'archived_at' => now(),
        ]);

        Cache::forget('retrain:ml_idle_status');

        return response()->json([
            'message'          => $count . ' retraining ' . ($count === 1 ? 'run' : 'runs') . ' archived successfully.',
            'archived_count'   => $count,
            'history'          => $this->buildHistory([], false),
            'archived_history' => $this->buildHistory([], true),
        ]);
    }

    /**
     * POST /api/admin/retrain/history/recover
     * Recover one or more archived retrain history runs back to active
     */
    public function recoverHistory(Request $request)
    {
        $ids = $this->extractHistoryIds($request);
        if (empty($ids)) {
            return response()->json(['message' => 'No valid history run IDs provided.'], 422);
        }

        $count = RetrainRun::whereIn('id', $ids)->update([
            'is_archived' => false,
            'archived_at' => null,
        ]);

        Cache::forget('retrain:ml_idle_status');

        return response()->json([
            'message'          => $count . ' retraining ' . ($count === 1 ? 'run' : 'runs') . ' recovered successfully.',
            'recovered_count'  => $count,
            'history'          => $this->buildHistory([], false),
            'archived_history' => $this->buildHistory([], true),
        ]);
    }

    /**
     * POST /api/admin/retrain/history/delete
     * Permanently delete one or more retrain history runs
     */
    public function deleteHistory(Request $request)
    {
        $ids = $this->extractHistoryIds($request);
        if (empty($ids)) {
            return response()->json(['message' => 'No valid history run IDs provided.'], 422);
        }

        $count = RetrainRun::whereIn('id', $ids)->delete();

        Cache::forget('retrain:ml_idle_status');

        return response()->json([
            'message'          => $count . ' retraining ' . ($count === 1 ? 'run' : 'runs') . ' permanently deleted.',
            'deleted_count'    => $count,
            'history'          => $this->buildHistory([], false),
            'archived_history' => $this->buildHistory([], true),
        ]);
    }

    /**
     * Helper to extract an array of integer IDs from request payload
     */
    private function extractHistoryIds(Request $request): array
    {
        $rawIds = $request->input('ids');
        if (! is_array($rawIds) && $request->has('id')) {
            $rawIds = [$request->input('id')];
        }

        if (! is_array($rawIds)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', $rawIds), fn ($id) => $id > 0));
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
