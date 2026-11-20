<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            if (!Schema::hasColumn('scans', 'image_hash')) {
                $table->string('image_hash', 64)->nullable()->index()->after('image_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            if (Schema::hasColumn('scans', 'image_hash')) {
                $table->dropIndex(['image_hash']);
                $table->dropColumn('image_hash');
            }
        });
    }
};

