<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('messages', 'delivery_status')) {
                $table->string('delivery_status', 24)->nullable()->after('ai_generated');
            }
            if (! Schema::hasColumn('messages', 'delivered_at')) {
                $table->timestamp('delivered_at')->nullable()->after('delivery_status');
            }
            if (! Schema::hasColumn('messages', 'read_at')) {
                $table->timestamp('read_at')->nullable()->after('delivered_at');
            }
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->index(['workspace_id', 'conversation_id', 'delivery_status'], 'messages_ws_conv_delivery_idx');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex('messages_ws_conv_delivery_idx');

            $columns = array_values(array_filter([
                Schema::hasColumn('messages', 'delivery_status') ? 'delivery_status' : null,
                Schema::hasColumn('messages', 'delivered_at') ? 'delivered_at' : null,
                Schema::hasColumn('messages', 'read_at') ? 'read_at' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
