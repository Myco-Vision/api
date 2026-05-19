<?php

namespace App\Http\Controllers;

use App\Models\Species;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SpeciesController extends Controller
{
    // GET /api/species  (public)
    public function index()
    {
        return response()->json(Species::latest()->get());
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
            'name'            => 'required|string|max:255',
            'scientific_name' => 'nullable|string|max:255',
            'classification'  => 'required|in:edible,poisonous,unknown',
            'description'     => 'nullable|string',
            'image'           => 'nullable|image|max:5120',
            'habitat'         => 'nullable|string|max:255',
        ]);

        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('species', 'public');
        }
        unset($data['image']);

        $species = Species::create($data);

        return response()->json($species, 201);
    }

    // PUT /api/species/{species}  (admin only)
    public function update(Request $request, Species $species)
    {
        $data = $request->validate([
            'name'            => 'sometimes|string|max:255',
            'scientific_name' => 'nullable|string|max:255',
            'classification'  => 'sometimes|in:edible,poisonous,unknown',
            'description'     => 'nullable|string',
            'image'           => 'nullable|image|max:5120',
            'habitat'         => 'nullable|string|max:255',
        ]);

        if ($request->hasFile('image')) {
            if ($species->image_path) {
                Storage::disk('public')->delete($species->image_path);
            }
            $data['image_path'] = $request->file('image')->store('species', 'public');
        }
        unset($data['image']);

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
