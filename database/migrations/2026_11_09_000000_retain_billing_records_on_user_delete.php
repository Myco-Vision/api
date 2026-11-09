<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep billing records (subscriptions, refund requests, subscription events)
 * when a user deletes their account: user_id becomes NULL instead of cascading.
 * `deleted_user_email` preserves who the record belonged to for accounting/disputes.
 */
return new class extends Migration
{
    private array $tables = ['subscriptions', 'refund_requests', 'subscription_events'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable()->change();
                $table->string('deleted_user_email')->nullable();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropColumn('deleted_user_email');
            });
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->nullable(false)->change();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    }
};
