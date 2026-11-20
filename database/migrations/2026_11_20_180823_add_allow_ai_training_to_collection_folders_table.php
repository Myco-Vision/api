<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collection_folders', function (Blueprint $table) {
            $table->boolean('allow_ai_training')->default(false)->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('collection_folders', function (Blueprint $table) {
            $table->dropColumn('allow_ai_training');
        });
    }
};
