<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('auto_renew')->default(true)->after('status');
            // Gateway-level payment state: paid, failed, refunded (null = derive from payment id)
            $table->string('payment_status')->nullable()->after('payment_method');
        });

        // Audit trail: plan switches, admin grants/cancels/revokes, payment activations
        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action'); // activated, granted, extended, cancelled, revoked, auto_renew_on, auto_renew_off, synced
            $table->json('details')->nullable();
            $table->timestamps();
        });

        // Key/value store for dynamic configuration (plan pricing, feature flags, gateway mode)
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('subscription_events');
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['auto_renew', 'payment_status']);
        });
    }
};
