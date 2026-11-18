<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('species', function (Blueprint $table) {
            if (!Schema::hasColumn('species', 'rarity')) {
                $table->string('rarity', 50)->default('common')->after('habitat');
            }
            if (!Schema::hasColumn('species', 'unlock_points')) {
                $table->integer('unlock_points')->default(5)->after('rarity');
            }
            if (!Schema::hasColumn('species', 'unlock_hint')) {
                $table->text('unlock_hint')->nullable()->after('unlock_points');
            }
            if (!Schema::hasColumn('species', 'is_active_mycodex')) {
                $table->boolean('is_active_mycodex')->default(true)->after('unlock_hint');
            }
        });
    }

    public function down(): void
    {
        Schema::table('species', function (Blueprint $table) {
            $table->dropColumn(['rarity', 'unlock_points', 'unlock_hint', 'is_active_mycodex']);
        });
    }
};

