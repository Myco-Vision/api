<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            // Human-readable place name (e.g. "Brgy. X, City, Province")
            // Guarded: may already exist from an earlier migration on some branches
            if (!Schema::hasColumn('scans', 'location_name')) {
                $table->string('location_name')->nullable()->after('longitude');
            }
            // 'gps' = device location at scan time, 'manual' = typed-in coordinates (e.g. picked offline)
            if (!Schema::hasColumn('scans', 'location_source')) {
                $table->string('location_source', 20)->nullable()->after('location_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            // Only drop location_source; location_name is owned by an earlier migration
            if (Schema::hasColumn('scans', 'location_source')) {
                $table->dropColumn('location_source');
            }
        });
    }
};
