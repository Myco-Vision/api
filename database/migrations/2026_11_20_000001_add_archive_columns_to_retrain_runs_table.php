<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retrain_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('retrain_runs', 'is_archived')) {
                $table->boolean('is_archived')->default(false)->index();
            }
            if (! Schema::hasColumn('retrain_runs', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('retrain_runs', function (Blueprint $table) {
            if (Schema::hasColumn('retrain_runs', 'is_archived')) {
                $table->dropColumn('is_archived');
            }
            if (Schema::hasColumn('retrain_runs', 'archived_at')) {
                $table->dropColumn('archived_at');
            }
        });
    }
};
