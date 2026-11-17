<?php

namespace App\Http\Controllers;

use App\Models\Scan;
use App\Models\ScanContribution;
use App\Models\Species;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class AdminAnnotationController extends Controller
{
    /**
     * Read image bytes for a contribution across local storage and remote S3/Supabase.
     */
    private function readImageBytes(string $imagePath): ?array
    {
        if (empty($imagePath)) {
            return null;
        }

        // Direct HTTP(S) URL
        if (str_starts_with($imagePath, 'http://') || str_starts_with($imagePath, 'https://')) {
            try {
                $res = Http::timeout(6)->get($imagePath);
                if ($res->successful() && $res->body() !== '') {
                    $pathPart = parse_url($imagePath, PHP_URL_PATH) ?: '';
                    return [
                        'bytes'    => $res->body(),
                        'filename' => basename($pathPart) ?: 'image.jpg',
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning("Annotation export: HTTP fetch failed for {$imagePath}: {$e->getMessage()}");
            }
            return null;
        }

        // Public disk storage
        $disk = Storage::disk('public');
        try {
            if ($disk->exists($imagePath)) {
                $bytes = $disk->get($imagePath);
                if ($bytes !== null && $bytes !== '') {
                    return [
                        'bytes'    => $bytes,
                        'filename' => basename($imagePath),
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Annotation export: storage read failed for {$imagePath}: {$e->getMessage()}");
        }

        // Public S3/Supabase base URL fallback
        $publicBaseUrl = config('filesystems.disks.s3.url') ?: env('AWS_URL');
        if ($publicBaseUrl) {
            $remoteUrl = rtrim((string) $publicBaseUrl, '/') . '/' . ltrim($imagePath, '/');
            try {
                $res = Http::timeout(6)->get($remoteUrl);
                if ($res->successful() && $res->body() !== '') {
                    return [
                        'bytes'    => $res->body(),
                        'filename' => basename($imagePath),
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning("Annotation export: remote fallback failed for {$remoteUrl}: {$e->getMessage()}");
            }
        }

        // Local filesystem fallback
        $localPath = storage_path('app/public/' . ltrim($imagePath, '/'));
        if (file_exists($localPath)) {
            $bytes = @file_get_contents($localPath);
            if ($bytes !== false && $bytes !== '') {
                return [
                    'bytes'    => $bytes,
                    'filename' => basename($imagePath),
                ];
            }
        }

        return null;
    }

    /**
     * GET /api/admin/annotations/pending
     * Lists pending contributions awaiting human expert review.
     */
    public function pending(Request $request)
    {
        $contributions = ScanContribution::where('annotation_status', 'pending')
            ->with(['user:id,name,email', 'scan:id,result_name,result_classification,confidence_level,created_at'])
            ->latest('id')
            ->paginate(15);

        // Fetch catalog species for the relabel dropdown
        $speciesList = Species::orderBy('name')->get(['id', 'name', 'scientific_name', 'classification']);

        return response()->json([
            'contributions' => $contributions,
            'species_list'  => $speciesList,
            'pending_count' => ScanContribution::where('annotation_status', 'pending')->count(),
            'approved_count'=> ScanContribution::where('annotation_status', 'approved')->count(),
        ]);
    }

    /**
     * GET /api/admin/annotations/approved
     * Lists verified dataset items ready for retraining.
     */
    public function approved(Request $request)
    {
        $approved = ScanContribution::where('annotation_status', 'approved')
            ->with(['user:id,name', 'scan:id,result_classification,retrained_at'])
            ->latest('updated_at')
            ->paginate(20);

        return response()->json([
            'contributions'  => $approved,
            'total_approved' => ScanContribution::where('annotation_status', 'approved')->count(),
        ]);
    }

    /**
     * POST /api/admin/annotations/{id}/approve
     * Approves annotation, sets verified_species, and transfers pending points to verified points.
     */
    public function approve(Request $request, $id)
    {
        $contribution = ScanContribution::findOrFail($id);

        if ($contribution->annotation_status === 'approved') {
            return response()->json(['message' => 'Annotation is already approved.'], 200);
        }

        $validated = $request->validate([
            'verified_species' => 'nullable|string|max:255',
        ]);

        $verifiedSpecies = $validated['verified_species'] ?: ($contribution->verified_species ?: $contribution->predicted_species);

        DB::transaction(function () use ($contribution, $verifiedSpecies) {
            $previousStatus = $contribution->annotation_status;
            $points = (int) $contribution->points_awarded;

            $contribution->verified_species = $verifiedSpecies;
            $contribution->annotation_status = 'approved';
            $contribution->save();

            // Make associated scan eligible for retraining
            if ($contribution->scan_id) {
                Scan::where('id', $contribution->scan_id)->update(['retrained_at' => null]);
            }

            // Transfer points from pending to verified
            if ($previousStatus === 'pending' && $points > 0 && $contribution->user_id) {
                $user = User::where('id', $contribution->user_id)->lockForUpdate()->first();
                if ($user) {
                    $deduct = min((int) $user->pending_points, $points);
                    $user->decrement('pending_points', $deduct);
                    $user->increment('verified_points', $points);
                }
            }
        });

        return response()->json([
            'message'          => "Annotation approved as '{$verifiedSpecies}'. Points moved to user verified points.",
            'contribution'     => $contribution->fresh()->load('user:id,name,pending_points,verified_points'),
        ]);
    }

    /**
     * POST /api/admin/annotations/{id}/reject
     * Rejects annotation, removes pending points without adding to verified, excludes from retraining.
     */
    public function reject(Request $request, $id)
    {
        $contribution = ScanContribution::findOrFail($id);

        if ($contribution->annotation_status === 'rejected') {
            return response()->json(['message' => 'Annotation is already rejected.'], 200);
        }

        DB::transaction(function () use ($contribution) {
            $previousStatus = $contribution->annotation_status;
            $points = (int) $contribution->points_awarded;

            $contribution->annotation_status = 'rejected';
            $contribution->save();

            // Exclude scan from active retraining
            if ($contribution->scan_id) {
                Scan::where('id', $contribution->scan_id)->update(['retrained_at' => now()]);
            }

            // Deduct points from pending_points
            if ($previousStatus === 'pending' && $points > 0 && $contribution->user_id) {
                $user = User::where('id', $contribution->user_id)->lockForUpdate()->first();
                if ($user) {
                    $deduct = min((int) $user->pending_points, $points);
                    $user->decrement('pending_points', $deduct);
                }
            }
        });

        return response()->json([
            'message'      => 'Annotation rejected. Points deducted from user pending points.',
            'contribution' => $contribution->fresh()->load('user:id,name,pending_points,verified_points'),
        ]);
    }

    /**
     * GET /api/admin/annotations/export
     * Exports all approved images organized by verified_species subfolders + metadata.csv.
     */
    public function export(Request $request)
    {
        $approved = ScanContribution::where('annotation_status', 'approved')
            ->with('user:id,name,email')
            ->get();

        if ($approved->isEmpty()) {
            return response()->json([
                'message' => 'No approved annotations found to export.',
            ], 422);
        }

        $tmpDir = sys_get_temp_dir();
        $zipFilename = 'mycovision_verified_dataset_' . now()->format('Ymd_His') . '.zip';
        $zipPath = $tmpDir . DIRECTORY_SEPARATOR . $zipFilename;

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return response()->json(['message' => 'Could not create ZIP archive on server.'], 500);
        }

        $csvLines = [];
        $csvLines[] = "contribution_id,scan_id,user_name,user_email,verified_species,predicted_species,confidence_score,points_awarded,file_path_in_zip,approved_at";

        $exportedCount = 0;

        foreach ($approved as $item) {
            $speciesFolder = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $item->verified_species ?: $item->predicted_species);
            if (empty($speciesFolder)) {
                $speciesFolder = 'unknown_species';
            }

            $fileData = $this->readImageBytes((string) $item->image_path);
            if (!$fileData) {
                continue;
            }

            $filename = basename($fileData['filename']);
            $uniqueFilename = "contrib_{$item->id}_{$filename}";
            $inZipPath = "{$speciesFolder}/{$uniqueFilename}";

            $zip->addFromString($inZipPath, $fileData['bytes']);

            $safeVerified = str_replace('"', '""', $item->verified_species ?: $item->predicted_species);
            $safePredicted = str_replace('"', '""', $item->predicted_species);
            $safeUserName = str_replace('"', '""', $item->user?->name ?? 'Unknown');
            $safeEmail = str_replace('"', '""', $item->user?->email ?? '');

            $csvLines[] = sprintf(
                '%d,%d,"%s","%s","%s","%s",%.4f,%d,"%s","%s"',
                $item->id,
                $item->scan_id,
                $safeUserName,
                $safeEmail,
                $safeVerified,
                $safePredicted,
                $item->confidence_score,
                $item->points_awarded,
                $inZipPath,
                $item->updated_at?->toIso8601String() ?? now()->toIso8601String()
            );

            $exportedCount++;
        }

        $metadataCsv = implode("\n", $csvLines);
        $zip->addFromString('metadata.csv', $metadataCsv);
        $zip->close();

        if ($exportedCount === 0 || !file_exists($zipPath)) {
            @unlink($zipPath);
            return response()->json(['message' => 'None of the approved scan images could be retrieved from storage.'], 422);
        }

        return response()->download($zipPath, $zipFilename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }
}

