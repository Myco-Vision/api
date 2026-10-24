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
            $table->string('location_name')->nullable()->after('longitude');
            // 'gps' = device location at scan time, 'manual' = typed-in coordinates (e.g. picked offline)
            $table->string('location_source', 20)->nullable()->after('location_name');
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->dropColumn(['location_name', 'location_source']);
        });
    }
};
