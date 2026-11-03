<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            // Set once a scan image has been sent to the ML service for retraining,
            // so it is not offered / re-sent on the next retrain run.
            if (!Schema::hasColumn('scans', 'retrained_at')) {
                $table->timestamp('retrained_at')->nullable()->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            if (Schema::hasColumn('scans', 'retrained_at')) {
                $table->dropColumn('retrained_at');
            }
        });
    }
};
