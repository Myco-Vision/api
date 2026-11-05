<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return view('welcome');
});

// When uploads live in object storage (PUBLIC_DISK_DRIVER=s3) or local storage,
// serve or redirect /storage/{path} gracefully.
Route::get('/storage/{path}', function (string $path) {
    if (config('filesystems.disks.public.driver') === 's3') {
        $supabaseUrl = env('AWS_URL');
        if ($supabaseUrl) {
            return redirect()->away(rtrim($supabaseUrl, '/') . '/' . ltrim($path, '/'));
        }
        return redirect()->away(Storage::disk('public')->url($path));
    }
    if (!Storage::disk('public')->exists($path)) {
        abort(404);
    }
    return Storage::disk('public')->response($path);
})->where('path', '.*');
