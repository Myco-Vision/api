<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'allow_data_training')) {
                $table->boolean('allow_data_training')->default(false)->after('profile_photo');
            }
            if (!Schema::hasColumn('users', 'data_training_consented_at')) {
                $table->timestamp('data_training_consented_at')->nullable()->after('allow_data_training');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = array_filter(
                ['allow_data_training', 'data_training_consented_at'],
                fn ($col) => Schema::hasColumn('users', $col)
            );
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
