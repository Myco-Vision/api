<?php

namespace App\Http\Controllers;

use App\Models\CollectionFolder;
use App\Models\CollectionItem;
use Illuminate\Http\Request;

class CollectionController extends Controller
{
    // GET /api/collections  — list user's folders with item counts
    public function index(Request $request)
    {
        $folders = $request->user()
            ->collectionFolders()
            ->withCount('items')
            ->orderBy('created_at')
            ->get();

        return response()->json($folders);
    }

    // POST /api/collections  — create a folder
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'              => 'required|string|max:80',
            'color'             => 'sometimes|string|max:20',
            'allow_ai_training' => 'sometimes|boolean',
        ]);

        $folder = $request->user()->collectionFolders()->create($data);

        return response()->json($folder->loadCount('items'), 201);
    }

    // PUT /api/collections/{folder}  — rename / recolour / toggle consent
    public function update(Request $request, CollectionFolder $folder)
    {
        $this->authorise($request, $folder);

        $data = $request->validate([
            'name'             => 'sometimes|string|max:80',
            'color'            => 'sometimes|string|max:20',
            'allow_ai_training'=> 'sometimes|boolean',
        ]);

        $folder->update($data);

        return response()->json($folder->loadCount('items'));
    }

    // DELETE /api/collections/{folder}  — delete folder (not the scans)
    public function destroy(Request $request, CollectionFolder $folder)
    {
        $this->authorise($request, $folder);
        $folder->delete();

        return response()->json(['message' => 'Folder deleted.']);
    }

    // GET /api/collections/{folder}/scans  — list scans in a folder
    public function scans(Request $request, CollectionFolder $folder)
    {
        $this->authorise($request, $folder);

        $scans = $folder->scans()->with('species')->get();

        return response()->json($scans);
    }

    // POST /api/collections/{folder}/scans  — add a scan to a folder
    public function addScan(Request $request, CollectionFolder $folder)
    {
        $this->authorise($request, $folder);

        $data = $request->validate([
            'scan_id' => 'required|integer|exists:scans,id',
        ]);

        // Ensure the scan belongs to this user
        $scan = $request->user()->scans()->findOrFail($data['scan_id']);

        $folder->scans()->syncWithoutDetaching([$scan->id]);

        return response()->json(['message' => 'Scan added to folder.'], 201);
    }

    // DELETE /api/collections/{folder}/scans/{scan}  — remove scan from folder
    public function removeScan(Request $request, CollectionFolder $folder, int $scanId)
    {
        $this->authorise($request, $folder);

        $folder->scans()->detach($scanId);

        return response()->json(['message' => 'Scan removed from folder.']);
    }

    // GET /api/collections/scan/{scan}/folders  — which folders contain a given scan
    public function foldersForScan(Request $request, int $scanId)
    {
        $folderIds = $request->user()
            ->collectionFolders()
            ->whereHas('scans', fn($q) => $q->where('scans.id', $scanId))
            ->pluck('id');

        return response()->json($folderIds);
    }

    private function authorise(Request $request, CollectionFolder $folder): void
    {
        if ($folder->user_id !== $request->user()->id) {
            abort(403);
        }
    }
}
