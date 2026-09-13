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
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 64);
            $table->string('event_type', 128)->nullable();
            $table->string('external_event_id')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->json('headers')->nullable();
            $table->json('payload');
            $table->enum('status', ['pending', 'processed', 'failed', 'duplicate', 'invalid'])->default('pending');
            $table->timestamp('processed_at')->nullable();
            $table->text('failed_reason')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'idempotency_key']);
            $table->unique(['provider', 'external_event_id']);
            $table->index(['workspace_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
