<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_billing_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('checkout_session_id')->nullable()->constrained('subscription_checkout_sessions')->nullOnDelete();
            $table->string('purpose', 32);
            $table->string('status', 32)->default('pending');
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->string('payment_provider', 64);
            $table->string('provider_checkout_id')->nullable();
            $table->string('provider_event_id')->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('period_key', 32)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status'], 'sub_bill_att_ws_status_idx');
            $table->index(['subscription_id', 'purpose', 'period_key'], 'sub_bill_att_sub_period_idx');
            $table->index(['provider_checkout_id'], 'sub_bill_att_provider_chk_idx');
            $table->unique(['payment_provider', 'provider_event_id'], 'sub_bill_att_provider_event_uidx');
        });

        if (Schema::hasTable('subscription_checkout_sessions')) {
            Schema::table('subscription_checkout_sessions', function (Blueprint $table): void {
                $table->unique('provider_checkout_id', 'sub_chk_sessions_provider_chk_uidx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('subscription_checkout_sessions')) {
            Schema::table('subscription_checkout_sessions', function (Blueprint $table): void {
                $table->dropUnique('sub_chk_sessions_provider_chk_uidx');
            });
        }

        Schema::dropIfExists('subscription_billing_attempts');
    }
};
