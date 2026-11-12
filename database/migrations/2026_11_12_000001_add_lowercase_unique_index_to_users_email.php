<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $hasDupes = DB::table('users')
            ->selectRaw('LOWER(email) as normalized_email, COUNT(*) as cnt')
            ->groupByRaw('LOWER(email)')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if (! $hasDupes) {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_unique ON users (LOWER(email))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
        }
    }
};

