<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_devices')) {
            Schema::create('pos_devices', function (Blueprint $table): void {
                $table->id();
                $table->string('device_id', 64);
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('name')->default('كاشير حاسم');
                $table->string('platform', 40)->default('cashier');
                $table->timestamp('registered_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();

                // Globally unique: a device_id cannot bind to another workspace.
                $table->unique('device_id');
                $table->index(['workspace_id', 'device_id']);
            });
        }

        if (! Schema::hasTable('pos_sync_changes')) {
            Schema::create('pos_sync_changes', function (Blueprint $table): void {
                // Auto-increment id is the monotonic server cursor/version.
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->string('entity_type', 40);
                $table->unsignedBigInteger('entity_id')->nullable();
                $table->string('operation', 20);
                $table->json('payload')->nullable();
                $table->string('origin_device_id', 64)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['workspace_id', 'id']);
                $table->index(['workspace_id', 'entity_type', 'entity_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sync_changes');
        Schema::dropIfExists('pos_devices');
    }
};
