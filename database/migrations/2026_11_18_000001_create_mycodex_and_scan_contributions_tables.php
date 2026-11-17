<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add pending_points and verified_points to users
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'pending_points')) {
                $table->integer('pending_points')->default(0)->after('reward_points');
            }
            if (!Schema::hasColumn('users', 'verified_points')) {
                $table->integer('verified_points')->default(0)->after('pending_points');
            }
        });

        // 2. Create user_mycodex collection table
        if (!Schema::hasTable('user_mycodex')) {
            Schema::create('user_mycodex', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('species_name');
                $table->timestamp('first_scanned_at')->useCurrent();
                $table->integer('scan_count')->default(1);
                $table->string('cover_image_path')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'species_name']);
                $table->index(['user_id', 'created_at']);
            });
        }

        // 3. Create scan_contributions staging/HITL annotation table
        if (!Schema::hasTable('scan_contributions')) {
            Schema::create('scan_contributions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('scan_id')->constrained('scans')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('image_path');
                $table->string('predicted_species');
                $table->string('verified_species')->nullable();
                $table->float('confidence_score')->default(0);
                $table->string('annotation_status')->default('pending'); // pending, approved, rejected
                $table->integer('points_awarded')->default(0);
                $table->boolean('is_new_species_for_user')->default(false);
                $table->timestamps();

                $table->index('annotation_status');
                $table->index(['user_id', 'annotation_status']);
                $table->index(['created_at', 'annotation_status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_contributions');
        Schema::dropIfExists('user_mycodex');

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'verified_points')) {
                $table->dropColumn('verified_points');
            }
            if (Schema::hasColumn('users', 'pending_points')) {
                $table->dropColumn('pending_points');
            }
        });
    }
};
