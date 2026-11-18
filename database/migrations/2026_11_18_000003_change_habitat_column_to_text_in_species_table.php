<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE species ALTER COLUMN habitat TYPE text;');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE species ALTER COLUMN habitat TYPE varchar(255);');
    }
};

