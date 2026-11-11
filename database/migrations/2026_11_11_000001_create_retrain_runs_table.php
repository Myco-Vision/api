<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('retrain_runs')) {
            return;
        }

        Schema::create('retrain_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('running'); // running | done | failed
            $table->unsignedInteger('new_edible')->default(0);
            $table->unsignedInteger('new_poisonous')->default(0);
            $table->unsignedInteger('total_edible')->nullable();
            $table->unsignedInteger('total_poisonous')->nullable();
            $table->unsignedInteger('total_not_mushroom')->nullable();
            $table->unsignedInteger('total_images')->nullable();
            $table->float('train_accuracy')->nullable();
            $table->float('val_accuracy')->nullable();
            $table->text('error')->nullable();
            $table->json('scan_ids')->nullable();
            $table->boolean('scans_marked_retrained')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retrain_runs');
    }
};

