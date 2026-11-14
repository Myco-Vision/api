<?php

namespace App\Http\Controllers;

use App\Models\Scan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ScanController extends Controller
{
    // GET /api/scans  (user's own scans)
    public function index(Request $request)
    {
        $scans = $request->user()
            ->scans()
            ->with('species')
            ->latest()
            ->get();

        return response()->json($scans);
    }

    // POST /api/scans
    public function store(Request $request)
    {
        $data = $request->validate([
            'image'    => 'required|image|max:10240',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude'=> 'nullable|numeric|between:-180,180',
            'location_name'   => 'nullable|string|max:255',
            'location_source' => 'nullable|in:gps,manual',
            'notes'    => 'nullable|string',
        ]);

        $user = $request->user();

        // Check if user has active subscription or remaining free scans
        if (!$user->canScan()) {
            return response()->json([
                'message'               => 'Free scan limit reached (3 of 3 scans used). Please upgrade to Premium for unlimited scans.',
                'error'                 => 'SCAN_LIMIT_REACHED',
                'remaining_scans'       => 0,
                'requires_subscription' => true,
            ], 403);
        }

        $file = $request->file('image');
        $mlApiUrl = env('ML_API_URL', 'http://127.0.0.1:5000');

        set_time_limit(120); // Give extra time for ML inference on CPU
        $mlApiUrl = rtrim(config('services.ml.url'), '/');
        $speciesRecord = null;

        // 1. Run ML inference first using the uploaded temp file (not yet written to public storage)
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(90)->attach(
                'image', file_get_contents($file->path()), $file->getClientOriginalName()
            )->post("{$mlApiUrl}/classify");

            if (!$response->successful()) {
                Log::error('ML API Error response', [
                    'status' => $response->status(),
                    'body'   => $response->body()
                ]);
                return response()->json([
                    'message' => 'Image classification failed. Please try again.'
                ], 502);
            }

            $mlData = $response->json();
        } catch (\Exception $e) {
            Log::error('ML API Exception', [
                'message' => $e->getMessage()
            ]);
            return response()->json([
                'message' => 'The mushroom classification service is currently offline. Please try again shortly.'
            ], 503);
        }

        // 2. Reject non-mushrooms before touching disk or database
        $resultClass = $mlData['result_classification'] ?? 'unknown';
        if ($resultClass === 'not_mushroom') {
        return response()->json([
            'error'                 => 'NOT_MUSHROOM',   // add this
            'message'               => 'No mushroom detected in the image. Please take a clearer photo of a mushroom.',
            'result_classification' => 'not_mushroom',
            'confidence_level'      => $mlData['confidence_level'] ?? 0,
        ], 422);
    }

        // 3. Inference passed and confirmed mushroom — save to storage and database
        $path = $file->store('scans', 'public');

        $resultName     = $mlData['result_name'] ?? 'Unknown';
        $scientificName = $mlData['scientific_name'] ?? $resultName;
        $commonName     = $mlData['common_name'] ?? $resultName;
        $confidence     = $mlData['confidence_level'] ?? 0;

        // Try to resolve a matching species record for the relation
        $speciesRecord = \App\Models\Species::where('scientific_name', $scientificName)
                            ->orWhere('scientific_name', $resultName)
                            ->orWhere('name', $commonName)
                            ->orWhere('name', $resultName)
                            ->first();

        $scan = Scan::create([
            'user_id'               => $user->id,
            'species_id'            => $speciesRecord->id ?? null,
            'image_path'            => $path,
            'result_name'           => $resultName,
            'result_classification' => $resultClass,
            'confidence_level'      => $confidence,
            'latitude'              => $data['latitude'] ?? null,
            'longitude'             => $data['longitude'] ?? null,
            'location_name'         => $data['location_name'] ?? null,
            'location_source'       => $data['location_source'] ?? (isset($data['latitude']) ? 'gps' : null),
            'notes'                 => $data['notes'] ?? null,
        ]);

        $rewardResult = (new \App\Services\RewardService())->awardForScan($user, $scan);
        $scanPayload = $scan->load('species')->toArray();
        $scanPayload['reward'] = $rewardResult;

        return response()->json($scanPayload, 201);
    }

    public function preview(Request $request)
    {
        $request->validate([
            'image' => 'required|image|max:10240',
        ]);

        $mlApiUrl = rtrim(config('services.ml.url'), '/');

        try {
            $file = $request->file('image');

            $response = \Illuminate\Support\Facades\Http::timeout(30)->attach(
                'image',
                file_get_contents($file->path()),
                $file->getClientOriginalName()
            )->post("{$mlApiUrl}/classify");

            if (! $response->successful()) {
                Log::error('ML preview error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return response()->json(['message' => 'Classification failed.'], 502);
            }

            return response()->json($response->json());
        } catch (\Exception $e) {
            Log::error('ML preview exception', ['message' => $e->getMessage()]);
            return response()->json(['message' => 'ML service offline.'], 503);
        }
    }

    // GET /api/scans/{scan}
    public function show(Request $request, Scan $scan)
    {
        // Only the owner (or admin) can view
        if ($scan->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json($scan->load('species', 'user'));
    }

    // PATCH /api/scans/{scan}/location
    public function updateLocation(Request $request, Scan $scan)
    {
        // Only the owner can update location
        if ($scan->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'latitude'  => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'location_name'   => 'nullable|string|max:255',
            'location_source' => 'nullable|in:gps,manual',
        ]);

        $scan->update([
            'latitude'        => $data['latitude'],
            'longitude'       => $data['longitude'],
            'location_name'   => $data['location_name'] ?? null,
            'location_source' => $data['location_source'] ?? 'gps',
        ]);

        return response()->json($scan->fresh());
    }

    // GET /api/scans/map
    public function map(Request $request)
    {
        $user = $request->user();
        $isSubscribed = $user->hasActiveSubscription();
        $scope = $request->input('scope', 'mine') === 'community' ? 'community' : 'mine';

        if ($scope === 'community' && !$isSubscribed) {
            return response()->json([
                'error'   => 'PREMIUM_REQUIRED',
                'message' => 'Community sightings & regional toxicity alerts require a Premium subscription.',
            ], 403);
        }

        $query = Scan::with('species:id,name,scientific_name,classification')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

        if ($scope === 'mine') {
            $query->where('user_id', $user->id);
        } else {
            $query->where('is_public_sighting', true);
        }

        // Apply filters (full filtering for Premium; basic mine view for Free)
        if ($isSubscribed || $scope === 'mine') {
            $classification = $request->input('classification');
            if (in_array($classification, ['edible', 'poisonous'], true)) {
                $query->where('result_classification', $classification);
            }

            if ($search = trim((string) $request->input('search', ''))) {
                $query->where(function ($q) use ($search) {
                    $q->where('result_name', 'like', "%{$search}%")
                      ->orWhere('location_name', 'like', "%{$search}%");
                });
            }

            if ($from = $request->input('date_from')) {
                $query->whereDate('created_at', '>=', $from);
            }
            if ($to = $request->input('date_to')) {
                $query->whereDate('created_at', '<=', $to);
            }
        }

        $query->latest('id');

        if ($scope === 'mine' && !$isSubscribed) {
            $query->take(\App\Models\User::FREE_SCAN_LIMIT);
        } else {
            $query->take(200);
        }

        $pins = $query->get()->map(function (Scan $scan) use ($scope, $user) {
            $isOwner = $scan->user_id === $user->id;
            return [
                'id'                    => $scan->id,
                'is_owner'              => $isOwner,
                'result_name'           => $scan->result_name,
                'result_classification' => $scan->result_classification,
                'confidence_level'      => $scan->confidence_level,
                'latitude'              => $scope === 'community' ? round((float) $scan->latitude, 2) : (float) $scan->latitude,
                'longitude'             => $scope === 'community' ? round((float) $scan->longitude, 2) : (float) $scan->longitude,
                'location_name'         => $scan->location_name,
                'location_source'       => $scan->location_source,
                'notes'                 => $isOwner ? $scan->notes : null,
                'is_public_sighting'    => $isOwner ? (bool) $scan->is_public_sighting : true,
                'image_url'             => $scan->image_url,
                'species'               => $scan->species,
                'created_at'            => $scan->created_at?->toIso8601String(),
            ];
        })->values();

        $unpinned = [];
        if ($scope === 'mine') {
            $unpinned = $user->scans()
                ->where(function ($q) {
                    $q->whereNull('latitude')->orWhereNull('longitude');
                })
                ->latest('id')
                ->take(20)
                ->get(['id', 'result_name', 'result_classification', 'image_path', 'created_at']);
        }

        return response()->json([
            'scope'          => $scope,
            'is_subscribed'  => $isSubscribed,
            'pins'           => $pins,
            'unpinned_scans' => $unpinned,
        ]);
    }

    // PATCH /api/scans/{scan}/details
    public function updateDetails(Request $request, Scan $scan)
    {
        if ($scan->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'notes'              => 'nullable|string|max:1000',
            'is_public_sighting' => 'nullable|boolean',
        ]);

        $updates = [];
        if (array_key_exists('notes', $data)) {
            $updates['notes'] = $data['notes'];
        }
        if (array_key_exists('is_public_sighting', $data)) {
            $updates['is_public_sighting'] = (bool) $data['is_public_sighting'];
        }

        $scan->update($updates);

        return response()->json($scan->fresh());
    }

    // DELETE /api/scans/{scan}
    public function destroy(Request $request, Scan $scan)
    {
        if ($scan->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        Storage::disk('public')->delete($scan->image_path);
        $scan->delete();

        return response()->json(['message' => 'Scan deleted.']);
    }
}
