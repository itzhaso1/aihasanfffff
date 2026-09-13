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
        Schema::table('email_messages', function (Blueprint $table) {
            $table->string('delivery_status', 32)->nullable()->after('type');
            $table->text('delivery_error')->nullable()->after('delivery_status');
            $table->timestamp('delivered_at')->nullable()->after('delivery_error');
            $table->index(['workspace_id', 'delivery_status'], 'email_messages_workspace_delivery_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropIndex('email_messages_workspace_delivery_status_idx');
            $table->dropColumn(['delivery_status', 'delivery_error', 'delivered_at']);
        });
    }
};
