<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->string('customer_priority', 32)->default('normal')->after('status');
            $table->string('payment_status', 32)->default('unpaid')->after('customer_priority');
            $table->string('review_status', 32)->default('no_review_needed')->after('payment_status');
            $table->string('classification_source', 16)->default('system')->after('review_status');
            $table->foreignId('classification_updated_by')->nullable()->after('classification_source')->constrained('users')->nullOnDelete();
            $table->timestamp('classification_updated_at')->nullable()->after('classification_updated_by');
            $table->string('classification_reason', 240)->nullable()->after('classification_updated_at');
            $table->index(['workspace_id', 'customer_priority', 'review_status'], 'conversations_classification_index');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex('conversations_classification_index');
            $table->dropConstrainedForeignId('classification_updated_by');
            $table->dropColumn([
                'customer_priority',
                'payment_status',
                'review_status',
                'classification_source',
                'classification_updated_at',
                'classification_reason',
            ]);
        });
    }
};
