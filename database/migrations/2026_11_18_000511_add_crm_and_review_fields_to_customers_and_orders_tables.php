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
        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table): void {
                if (! Schema::hasColumn('customers', 'status')) {
                    $table->string('status', 32)->default('needs_review')->after('email');
                    $table->index(['workspace_id', 'status'], 'customers_workspace_status_idx');
                }
            });
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table): void {
                if (! Schema::hasColumn('orders', 'conversation_id')) {
                    $table->foreignId('conversation_id')
                        ->nullable()
                        ->after('customer_id')
                        ->constrained('conversations')
                        ->nullOnDelete();
                    $table->index(['workspace_id', 'conversation_id'], 'orders_workspace_conversation_idx');
                }

                if (! Schema::hasColumn('orders', 'review_reason')) {
                    $table->string('review_reason', 255)->nullable()->after('notes');
                }

                if (! Schema::hasColumn('orders', 'review_required_action')) {
                    $table->string('review_required_action', 255)->nullable()->after('review_reason');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table): void {
                if (Schema::hasColumn('orders', 'conversation_id')) {
                    $table->dropForeign(['conversation_id']);
                    $table->dropIndex('orders_workspace_conversation_idx');
                    $table->dropColumn('conversation_id');
                }
                if (Schema::hasColumn('orders', 'review_reason')) {
                    $table->dropColumn('review_reason');
                }
                if (Schema::hasColumn('orders', 'review_required_action')) {
                    $table->dropColumn('review_required_action');
                }
            });
        }

        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table): void {
                if (Schema::hasColumn('customers', 'status')) {
                    $table->dropIndex('customers_workspace_status_idx');
                    $table->dropColumn('status');
                }
            });
        }
    }
};
