<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return view('welcome');
});

// When uploads live in object storage (PUBLIC_DISK_DRIVER=s3) there is no
// public/storage symlink, so /storage/{path} (used by the frontend) redirects
// to the file's public URL.
if (config('filesystems.disks.public.driver') === 's3') {
    Route::get('/storage/{path}', function (string $path) {
        return redirect()->away(Storage::disk('public')->url($path));
    })->where('path', '.*');
}
