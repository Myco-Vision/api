<?php

namespace App\Http\Controllers;

use App\Models\Species;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SpeciesController extends Controller
{
    // GET /api/species  (public & admin)
    public function index()
    {
        $species = Species::latest('id')->get()->map(function ($s) {
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

            $s->unlock_count = $unlockCount;
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
            'habitat'            => 'nullable|string|max:5000',
            'rarity'             => 'nullable|string|in:common,uncommon,rare,legendary',
            'unlock_points'      => 'nullable|integer|min:1|max:500',
            'unlock_hint'        => 'nullable|string',
            'is_active_mycodex'  => 'nullable|boolean',
            'scan_id'            => 'nullable|integer',
        ]);

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('species', 'public');
        } elseif (!empty($data['scan_id']) && empty($data['image_path'])) {
            $scan = \App\Models\Scan::find($data['scan_id']);
            if ($scan && $scan->image_path) {
                $data['image_path'] = $scan->image_path;
            }
        }
        unset($data['image'], $data['scan_id']);

        $data['rarity'] = $data['rarity'] ?? 'common';
        $data['unlock_points'] = isset($data['unlock_points']) ? (int) $data['unlock_points'] : 5;
        $data['is_active_mycodex'] = $request->has('is_active_mycodex')
            ? filter_var($request->input('is_active_mycodex'), FILTER_VALIDATE_BOOLEAN)
            : true;

        $species = Species::create($data);

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
        }
        unset($data['image']);

        if ($request->has('is_active_mycodex')) {
            $data['is_active_mycodex'] = filter_var($request->input('is_active_mycodex'), FILTER_VALIDATE_BOOLEAN);
        }

        $species->update($data);

        return response()->json($species);
    }

    // DELETE /api/species/{species}  (admin only)
    public function destroy(Species $species)
    {
        if ($species->image_path) {
            Storage::disk('public')->delete($species->image_path);
        }
        $species->delete();

        return response()->json(['message' => 'Species deleted.']);
    }
}
