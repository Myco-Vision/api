<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'reward_points')) {
                $table->unsignedInteger('reward_points')->default(0)->after('data_training_consented_at');
            }
        });

        if (!Schema::hasTable('point_transactions')) {
            Schema::create('point_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->integer('points'); // Positive for earned, negative for redeemed
                $table->string('type', 50); // scan_contribution, location_bonus, retrain_bonus, subscription_discount, subscription_redemption
                $table->string('description');
                $table->string('reference_type', 50)->nullable();
                $table->unsignedBigInteger('reference_id')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'created_at']);
                $table->index(['reference_type', 'reference_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('point_transactions');

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'reward_points')) {
                $table->dropColumn('reward_points');
            }
        });
    }
};
