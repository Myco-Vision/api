<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            // first_time_guarantee | duplicate_charge | service_failure | charged_after_cancel | annual_prorated | other
            $table->string('reason_type');
            $table->text('reason');
            $table->decimal('requested_amount', 10, 2)->default(0); // policy-computed suggestion at submit time
            $table->decimal('approved_amount', 10, 2)->nullable();
            $table->string('refund_type')->nullable();               // full | prorated
            $table->string('status')->default('pending');           // pending | approved | rejected | processing | refunded | failed
            $table->json('eligibility')->nullable();                 // snapshot of server-side checks
            $table->text('admin_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('paymongo_refund_id')->nullable()->index();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_requests');
    }
};
