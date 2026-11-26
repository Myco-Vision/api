<?php

namespace App\Http\Controllers;

use App\Models\Species;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SpeciesController extends Controller
{
    // GET /api/species  (public & admin)
    public function index(Request $request)
    {
        $query = Species::latest('id');

        if ($request->has('archived')) {
            $archived = filter_var($request->query('archived'), FILTER_VALIDATE_BOOLEAN);
            if ($archived) {
                $query->where('is_active_mycodex', false);
            } else {
                $query->where(function ($q) {
                    $q->where('is_active_mycodex', true)->orWhereNull('is_active_mycodex');
                });
            }
        } elseif ($request->query('status') === 'active') {
            $query->where(function ($q) {
                $q->where('is_active_mycodex', true)->orWhereNull('is_active_mycodex');
            });
        } elseif ($request->query('status') === 'archived') {
            $query->where('is_active_mycodex', false);
        }

        $species = $query->get()->map(function ($s) {
            $names = array_filter([
                strtolower(trim((string) $s->name)),
                strtolower(trim((string) $s->scientific_name)),
            ]);

            $unlockCount = 0;
            if (!empty($names)) {
                $unlockCount = \App\Models\UserMycodex::where(function ($q) use ($names) {
                    foreach ($names as $name) {
                        $q->orWhereRaw('LOWER(species_name) = ?', [$name]);
                    }
                })->distinct('user_id')->count('user_id');
            }

            $scanCount = \App\Models\Scan::where('species_id', $s->id)
                ->orWhere(function ($q) use ($s) {
                    if ($s->name) {
                        $q->orWhereRaw('LOWER(result_name) = ?', [strtolower(trim($s->name))]);
                    }
                })->count();

            $s->unlock_count = $unlockCount;
            $s->scan_count = $scanCount;
            $s->is_active_mycodex = $s->is_active_mycodex ?? true;
            return $s;
        });

        return response()->json($species);
    }

    // GET /api/species/{species}  (public)
    public function show(Species $species)
    {
        return response()->json($species);
    }

    // POST /api/species  (admin only)
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'               => 'required|string|max:255',
            'scientific_name'    => 'nullable|string|max:255',
            'classification'     => 'required|in:edible,poisonous,unknown',
            'description'        => 'nullable|string',
            'image'              => 'nullable|image|max:8192',
            'image_path'         => 'nullable|string|max:255',
            'silhouette'         => 'nullable|image|max:8192',
            'silhouette_path'    => 'nullable|string|max:255',
            'habitat'            => 'nullable|string|max:5000',
            'rarity'             => 'nullable|string|in:common,uncommon,rare,legendary',
            'unlock_points'      => 'nullable|integer|min:1|max:500',
            'unlock_hint'        => 'nullable|string',
            'is_active_mycodex'  => 'nullable|boolean',
            'scan_id'            => 'nullable|integer',
        ]);

        $scanId = !empty($data['scan_id']) ? (int) $data['scan_id'] : null;

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('species', 'public');
        } elseif ($scanId && empty($data['image_path'])) {
            $scan = \App\Models\Scan::find($scanId);
            if ($scan && $scan->image_path) {
                $data['image_path'] = $scan->image_path;
            }
        }
        unset($data['image'], $data['scan_id']);

        if ($request->hasFile('silhouette')) {
            $data['silhouette_path'] = $request->file('silhouette')->store('species/silhouettes', 'public');
        }
        unset($data['silhouette']);

        $data['rarity'] = $data['rarity'] ?? 'common';
        $data['unlock_points'] = isset($data['unlock_points']) ? (int) $data['unlock_points'] : 5;
        $data['is_active_mycodex'] = $request->has('is_active_mycodex')
            ? filter_var($request->input('is_active_mycodex'), FILTER_VALIDATE_BOOLEAN)
            : true;

        $species = Species::create($data);

        // When promoted from a candidate scan, link the scan and approve it for AI Model Retraining
        if ($scanId) {
            $scan = \App\Models\Scan::find($scanId);
            if ($scan) {
                $updatedClassification = in_array($species->classification, ['edible', 'poisonous'], true)
                    ? $species->classification
                    : (in_array($scan->result_classification, ['edible', 'poisonous'], true) ? $scan->result_classification : 'edible');

                $scan->update([
                    'species_id'            => $species->id,
                    'result_name'           => $species->name,
                    'result_classification' => $updatedClassification,
                    'retrained_at'          => null,
                ]);

                // Approve any pending HITL contribution (or create an approved contribution) so it reflects in AI Retraining
                $contribution = \App\Models\ScanContribution::where('scan_id', $scan->id)->first();
                if ($contribution) {
                    $previousStatus = $contribution->annotation_status;
                    $points = (int) $contribution->points_awarded;

                    $contribution->update([
                        'verified_species'  => $species->name,
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
                                'description'    => "AI data contribution verified via species catalog promotion ({$species->name})",
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
                        'predicted_species'       => $scan->result_name ?: $species->name,
                        'verified_species'        => $species->name,
                        'confidence_score'        => round($confScore, 4),
                        'annotation_status'       => 'approved',
                        'points_awarded'          => 0,
                        'is_new_species_for_user' => false,
                    ]);
                }
            }
        }

        return response()->json($species, 201);
    }

    // PUT /api/species/{species}  (admin only)
    public function update(Request $request, Species $species)
    {
        $data = $request->validate([
            'name'               => 'sometimes|string|max:255',
            'scientific_name'    => 'nullable|string|max:255',
            'classification'     => 'sometimes|in:edible,poisonous,unknown',
            'description'        => 'nullable|string',
            'image'              => 'nullable|image|max:8192',
            'image_path'         => 'nullable|string|max:255',
            'silhouette'         => 'nullable|image|max:8192',
            'silhouette_path'    => 'nullable|string|max:255',
            'habitat'            => 'nullable|string|max:5000',
            'rarity'             => 'nullable|string|in:common,uncommon,rare,legendary',
            'unlock_points'      => 'nullable|integer|min:1|max:500',
            'unlock_hint'        => 'nullable|string',
            'is_active_mycodex'  => 'nullable|boolean',
        ]);

        if ($request->hasFile('image')) {
            if ($species->image_path && !str_starts_with($species->image_path, 'http')) {
                Storage::disk('public')->delete($species->image_path);
            }
            $data['image_path'] = $request->file('image')->store('species', 'public');
        } elseif ($request->has('image_path') && empty($data['image_path'])) {
            if ($species->image_path && !str_starts_with($species->image_path, 'http')) {
                Storage::disk('public')->delete($species->image_path);
            }
            $data['image_path'] = null;
        }
        unset($data['image']);

        if ($request->hasFile('silhouette')) {
            if ($species->silhouette_path && !str_starts_with($species->silhouette_path, 'http')) {
                Storage::disk('public')->delete($species->silhouette_path);
            }
            $data['silhouette_path'] = $request->file('silhouette')->store('species/silhouettes', 'public');
        } elseif ($request->has('silhouette_path') && empty($data['silhouette_path'])) {
            if ($species->silhouette_path && !str_starts_with($species->silhouette_path, 'http')) {
                Storage::disk('public')->delete($species->silhouette_path);
            }
            $data['silhouette_path'] = null;
        }
        unset($data['silhouette']);

        if ($request->has('is_active_mycodex')) {
            $data['is_active_mycodex'] = filter_var($request->input('is_active_mycodex'), FILTER_VALIDATE_BOOLEAN);
        }

        $species->update($data);

        return response()->json($species);
    }

    // PUT /api/species/{species}/archive  (admin only)
    public function archive(Species $species)
    {
        $species->update([
            'is_active_mycodex' => false,
            'archived_at'       => now(),
        ]);

        return response()->json([
            'message' => "Species '{$species->name}' archived from active MycoDex field guide.",
            'species' => $species,
        ]);
    }

    // PUT /api/species/{species}/restore  (admin only)
    public function restore(Species $species)
    {
        $species->update([
            'is_active_mycodex' => true,
            'archived_at'       => null,
        ]);

        return response()->json([
            'message' => "Species '{$species->name}' restored to active MycoDex field guide.",
            'species' => $species,
        ]);
    }

    // POST /api/species/bulk-archive  (admin only)
    public function bulkArchive(Request $request)
    {
        $ids = (array) $request->input('ids', []);
        if (empty($ids)) {
            return response()->json(['message' => 'No species selected.'], 400);
        }

        Species::whereIn('id', $ids)->update([
            'is_active_mycodex' => false,
            'archived_at'       => now(),
        ]);

        return response()->json(['message' => count($ids) . ' species archived.']);
    }

    // POST /api/species/bulk-restore  (admin only)
    public function bulkRestore(Request $request)
    {
        $ids = (array) $request->input('ids', []);
        if (empty($ids)) {
            return response()->json(['message' => 'No species selected.'], 400);
        }

        Species::whereIn('id', $ids)->update([
            'is_active_mycodex' => true,
            'archived_at'       => null,
        ]);

        return response()->json(['message' => count($ids) . ' species restored.']);
    }

    // DELETE /api/species/{species}  (admin only - Smart Delete)
    public function destroy(Request $request, Species $species)
    {
        $force = filter_var($request->query('force', false), FILTER_VALIDATE_BOOLEAN)
            || filter_var($request->input('force', false), FILTER_VALIDATE_BOOLEAN);

        $unlockCount = \App\Models\UserMycodex::where(function ($q) use ($species) {
            if ($species->name) {
                $q->orWhereRaw('LOWER(species_name) = ?', [strtolower(trim($species->name))]);
            }
            if ($species->scientific_name) {
                $q->orWhereRaw('LOWER(species_name) = ?', [strtolower(trim($species->scientific_name))]);
            }
        })->count();

        $scanCount = \App\Models\Scan::where('species_id', $species->id)
            ->orWhere(function ($q) use ($species) {
                if ($species->name) {
                    $q->orWhereRaw('LOWER(result_name) = ?', [strtolower(trim($species->name))]);
                }
            })->count();

        if (($unlockCount > 0 || $scanCount > 0) && ! $force) {
            return response()->json([
                'error'                 => 'has_linked_data',
                'message'               => "Species '{$species->name}' is linked to {$scanCount} user scan(s) and has been unlocked by {$unlockCount} forager(s).",
                'unlock_count'          => $unlockCount,
                'scan_count'            => $scanCount,
                'requires_confirmation' => true,
            ], 422);
        }

        if ($species->image_path && !str_starts_with($species->image_path, 'http')) {
            Storage::disk('public')->delete($species->image_path);
        }
        $species->delete();

        return response()->json(['message' => "Species '{$species->name}' deleted."]);
    }

    // POST /api/species/bulk-delete  (admin only)
    public function bulkDelete(Request $request)
    {
        $ids = (array) $request->input('ids', []);
        $force = filter_var($request->input('force', false), FILTER_VALIDATE_BOOLEAN);

        if (empty($ids)) {
            return response()->json(['message' => 'No species selected.'], 400);
        }

        $speciesList = Species::whereIn('id', $ids)->get();
        $deleted = 0;
        $skipped = 0;

        foreach ($speciesList as $sp) {
            $unlocks = \App\Models\UserMycodex::where(function ($q) use ($sp) {
                if ($sp->name) {
                    $q->orWhereRaw('LOWER(species_name) = ?', [strtolower(trim($sp->name))]);
                }
                if ($sp->scientific_name) {
                    $q->orWhereRaw('LOWER(species_name) = ?', [strtolower(trim($sp->scientific_name))]);
                }
            })->count();

            $scans = \App\Models\Scan::where('species_id', $sp->id)->count();

            if (($unlocks > 0 || $scans > 0) && ! $force) {
                $skipped++;
                continue;
            }

            if ($sp->image_path && !str_starts_with($sp->image_path, 'http')) {
                Storage::disk('public')->delete($sp->image_path);
            }
            $sp->delete();
            $deleted++;
        }

        $msg = "{$deleted} species deleted.";
        if ($skipped > 0) {
            $msg .= " ({$skipped} species skipped because they have linked user scans/unlocks; archive them instead).";
        }

        return response()->json([
            'message' => $msg,
            'deleted' => $deleted,
            'skipped' => $skipped,
        ]);
    }
}
