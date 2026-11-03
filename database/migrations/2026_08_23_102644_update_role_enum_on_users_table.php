<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: widen enum to include old + new values so no data is lost
        DB::statement("ALTER TABLE users MODIFY role ENUM('user', 'forager', 'admin', 'super_admin') NOT NULL DEFAULT 'forager'");

        // Step 2: migrate old data
        DB::table('users')->where('role', 'user')->update(['role' => 'forager']);

        // Step 3: now safe to drop 'user' from the enum
        DB::statement("ALTER TABLE users MODIFY role ENUM('forager', 'admin', 'super_admin') NOT NULL DEFAULT 'forager'");
    }

    public function down(): void
    {
        // Widen again so 'user' is valid before we write it back
        DB::statement("ALTER TABLE users MODIFY role ENUM('user', 'forager', 'admin', 'super_admin') NOT NULL DEFAULT 'user'");

        DB::table('users')->where('role', 'forager')->update(['role' => 'user']);
        DB::table('users')->where('role', 'super_admin')->update(['role' => 'admin']);

        DB::statement("ALTER TABLE users MODIFY role ENUM('user', 'admin') NOT NULL DEFAULT 'user'");
    }
};