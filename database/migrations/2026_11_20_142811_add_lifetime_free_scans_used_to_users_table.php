<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('lifetime_free_scans_used')->default(0)->after('role');
        });

        // Backfill lifetime_free_scans_used from existing scans if scans table exists
        if (Schema::hasTable('scans')) {
            $users = DB::table('users')->select('id')->get();
            foreach ($users as $u) {
                $count = DB::table('scans')->where('user_id', $u->id)->count();
                DB::table('users')->where('id', $u->id)->update([
                    'lifetime_free_scans_used' => min(3, $count),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('lifetime_free_scans_used');
        });
    }
};
