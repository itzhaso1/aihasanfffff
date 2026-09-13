<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_sync_operations')) {
            Schema::create('pos_sync_operations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->string('device_id', 64);
                $table->string('operation_uuid', 160);
                $table->string('type', 80);
                $table->string('status', 20)->default('accepted');
                $table->string('entity_type', 40)->nullable();
                $table->unsignedBigInteger('entity_id')->nullable();
                $table->json('request_payload')->nullable();
                $table->json('result_payload')->nullable();
                $table->text('last_error')->nullable();
                $table->unsignedInteger('attempts')->default(1);
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();

                $table->unique(['workspace_id', 'operation_uuid'], 'pos_sync_ops_ws_uuid_unique');
                $table->index(['workspace_id', 'device_id', 'id']);
                $table->index(['workspace_id', 'status']);
            });
        }

        if (Schema::hasTable('pos_devices')) {
            Schema::table('pos_devices', function (Blueprint $table): void {
                if (! Schema::hasColumn('pos_devices', 'last_cursor')) {
                    $table->unsignedBigInteger('last_cursor')->nullable();
                }
                if (! Schema::hasColumn('pos_devices', 'last_sync_at')) {
                    $table->timestamp('last_sync_at')->nullable();
                }
                if (! Schema::hasColumn('pos_devices', 'last_error')) {
                    $table->text('last_error')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pos_devices')) {
            Schema::table('pos_devices', function (Blueprint $table): void {
                foreach (['last_cursor', 'last_sync_at', 'last_error'] as $column) {
                    if (Schema::hasColumn('pos_devices', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('pos_sync_operations');
    }
};
