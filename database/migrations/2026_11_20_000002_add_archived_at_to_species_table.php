<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('species')) {
            Schema::table('species', function (Blueprint $table) {
                if (! Schema::hasColumn('species', 'archived_at')) {
                    $table->timestamp('archived_at')->nullable()->after('is_active_mycodex');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('species')) {
            Schema::table('species', function (Blueprint $table) {
                if (Schema::hasColumn('species', 'archived_at')) {
                    $table->dropColumn('archived_at');
                }
            });
        }
    }
};

