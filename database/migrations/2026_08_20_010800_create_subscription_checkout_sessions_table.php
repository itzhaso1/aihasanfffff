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
        Schema::create('subscription_checkout_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('activated_subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();

            // Lifecycle states are separated to keep payment/subscription concerns decoupled.
            $table->enum('checkout_status', ['initiated', 'awaiting_payment', 'completed', 'cancelled', 'expired'])->default('initiated');
            $table->enum('payment_status', ['pending', 'processing', 'paid', 'failed', 'refunded'])->default('pending');
            $table->enum('subscription_status', ['pending_activation', 'activated', 'activation_failed', 'cancelled'])->default('pending_activation');

            $table->string('payment_provider', 64)->default('hyperpay');
            $table->string('provider_checkout_id')->nullable();
            $table->string('payment_reference')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('USD');
            $table->json('metadata')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason')->nullable();

            $table->timestamps();

            // Explicit short names to stay within MySQL's 64-char identifier limit.
            $table->index(['workspace_id', 'checkout_status'], 'sub_chk_sessions_ws_checkout_idx');
            $table->index(['workspace_id', 'payment_status'], 'sub_chk_sessions_ws_payment_idx');
            $table->index(['workspace_id', 'subscription_status'], 'sub_chk_sessions_ws_subscr_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_checkout_sessions');
    }
};
