<?php

use App\Http\Controllers\AccountManagementController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\SpeciesController;
use Illuminate\Support\Facades\Route;

// ── Public routes ──────────────────────────────────────────────────────────────
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);

// Public species listing (users can browse without logging in)
Route::get('/species',       [SpeciesController::class, 'index']);
Route::get('/species/{species}', [SpeciesController::class, 'show']);

// ── Authenticated routes ───────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me',      [AuthController::class, 'me']);

    // Scans (own)
    Route::get('/scans',          [ScanController::class, 'index']);
    Route::post('/scans',         [ScanController::class, 'store']);
    Route::get('/scans/{scan}',   [ScanController::class, 'show']);
    Route::patch('/scans/{scan}/location', [ScanController::class, 'updateLocation']);
    Route::delete('/scans/{scan}',[ScanController::class, 'destroy']);

    // ── Admin-only routes ────────────────────────────────────────────────────
    Route::middleware('admin')->prefix('admin')->group(function () {

        // Dashboard stats
        Route::get('/dashboard', [AdminController::class, 'dashboard']);

        // User management
        Route::get('/users',           [AdminController::class, 'users']);
        Route::get('/users/{user}',    [AdminController::class, 'showUser']);
        Route::put('/users/{user}',    [AdminController::class, 'updateUser']);
        Route::delete('/users/{user}', [AdminController::class, 'destroyUser']);

        // Scan overview
        Route::get('/scans', [AdminController::class, 'scans']);

        // Reports & Analytics
        Route::get('/reports', [AdminController::class, 'reports']);

        // Species management (read + write)
        Route::get('/species',              [SpeciesController::class, 'index']);
        Route::post('/species',             [SpeciesController::class, 'store']);
        Route::put('/species/{species}',    [SpeciesController::class, 'update']);
        Route::delete('/species/{species}', [SpeciesController::class, 'destroy']);
    });

    // ── Super Admin-only routes ──────────────────────────────────────────────
    Route::middleware('super_admin')->prefix('admin')->group(function () {

        // Account management (full CRUD)
        Route::get('/accounts',                        [AccountManagementController::class, 'index']);
        Route::post('/accounts',                       [AccountManagementController::class, 'store']);
        Route::get('/accounts/{user}',                 [AccountManagementController::class, 'show']);
        Route::put('/accounts/{user}',                 [AccountManagementController::class, 'update']);
        Route::delete('/accounts/{user}',              [AccountManagementController::class, 'destroy']);
        Route::post('/accounts/{user}/reset-password', [AccountManagementController::class, 'resetPassword']);
    });
});

