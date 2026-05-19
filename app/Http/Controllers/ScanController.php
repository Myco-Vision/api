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
            'latitude' => 'nullable|numeric',
            'longitude'=> 'nullable|numeric',
            'notes'    => 'nullable|string',
        ]);

        $path = $request->file('image')->store('scans', 'public');

        set_time_limit(120); // Give extra time for ML inference on CPU
        $mlApiUrl = env('ML_API_URL', 'http://127.0.0.1:8001');
        
        try {
            // Send the uploaded image to the Python FastAPI service (90s timeout)
            $response = \Illuminate\Support\Facades\Http::timeout(90)->attach(
                'image', file_get_contents($request->file('image')->path()), $request->file('image')->getClientOriginalName()
            )->post("{$mlApiUrl}/predict");

            if ($response->successful()) {
                $mlData = $response->json();
                $resultName = $mlData['species'] ?? 'Unknown';
                $confidence = $mlData['confidence'] ?? 0;

                // 1. Check if the AI returned a binary classification directly
                if (in_array(strtolower($resultName), ['edible', 'poisonous'])) {
                    $resultClass = strtolower($resultName);
                } else {
                    // 2. Look up specific species in database
                    $speciesRecord = \App\Models\Species::where('scientific_name', $resultName)
                                        ->orWhere('name', $resultName)
                                        ->first();

                    if ($speciesRecord) {
                        $resultClass = strtolower($speciesRecord->classification);
                    } else {
                        // 3. Fallback to list-based classification
                        $edibleSpecies = [
                            'Almond mushroom', 'Amethyst chanterelle', 'Aniseed funnel',
                            'Bay bolete', 'Beefsteak fungus', 'Birch polypore',
                            'Blushing wood mushroom', 'Bronze bolete', 'Brown birch bolete',
                            'Butter cap', 'Cauliflower fungus', 'Chanterelle',
                            'Charcoal burner', 'Chestnut bolete', 'Chicken of the woods',
                            'Clouded agaric', 'Common morel', 'Common puffball',
                            'Dryads saddle', 'Fairy ring champignons', 'Field blewit',
                            'Field mushroom', 'Fragrant funnel', 'Frosted chanterelle',
                            'Giant funnel', 'Giant puffball', 'Golden bootleg',
                            'Grisettes', 'Hedgehog fungus', 'Hen of the woods',
                            'Horn of plenty', 'Horse mushroom', 'Jelly ears',
                            'Larch bolete', 'Lions mane', 'Macro mushroom',
                            'Morel', 'Oak bolete', 'Oak polypore',
                            'Orange birch bolete', 'Orange grisette', 'Orange peel fungus',
                            'Oyster mushroom', 'Pale oyster', 'Parasol',
                            'Penny bun', 'Plums and custard', 'Saffron milkcap',
                            'Scarlet elfcup', 'Semifree morel', 'Shaggy inkcap',
                            'Shaggy parasol', 'Slippery jack', 'St georges mushroom',
                            'Summer bolete', 'Tawny grisette', 'The blusher',
                            'The miller', 'The prince', 'Thimble morel',
                            'Trooping funnel', 'Truffles', 'Turkey tail',
                            'Velvet shank', 'Winter chanterelle', 'Wood blewit',
                            'Wood mushroom', 'Yellow foot waxcap',
                        ];
                        $resultClass = in_array($resultName, $edibleSpecies) ? 'edible' : 'poisonous';
                    }
                }
            } else {
                Log::error('ML API Error response', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                $resultName = 'API Error';
                $resultClass = 'unknown';
                $confidence = 0;
            }
        } catch (\Exception $e) {
            Log::error('ML API Exception', [
                'message' => $e->getMessage()
            ]);
            $resultName = 'ML Service Offline';
            $resultClass = 'unknown';
            $confidence = 0;
        }

        $scan = Scan::create([
            'user_id'               => $request->user()->id,
            'image_path'            => $path,
            'result_name'           => $resultName,
            'result_classification' => $resultClass,
            'confidence_level'      => $confidence,
            'latitude'              => $data['latitude'] ?? null,
            'longitude'             => $data['longitude'] ?? null,
            'notes'                 => $data['notes'] ?? null,
        ]);

        return response()->json($scan->load('species'), 201);
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
