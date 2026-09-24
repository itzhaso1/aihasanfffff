<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_ai_conversations', function (Blueprint $table): void {
            if (! Schema::hasColumn('finance_ai_conversations', 'context_entities')) {
                $table->json('context_entities')->nullable()->after('last_metric_names');
            }
        });
    }

    public function down(): void
    {
        Schema::table('finance_ai_conversations', function (Blueprint $table): void {
            if (Schema::hasColumn('finance_ai_conversations', 'context_entities')) {
                $table->dropColumn('context_entities');
            }
        });
    }
};
