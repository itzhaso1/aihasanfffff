<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nullable employee references. No backfill: conversations are bound to an employee
 * the first time the new runtime selects one, and existing AI logs keep null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->foreignId('ai_employee_id')->nullable()->after('ai_enabled')->constrained('ai_employees')->nullOnDelete();
        });

        Schema::table('ai_logs', function (Blueprint $table): void {
            $table->unsignedBigInteger('ai_employee_id')->nullable()->after('message_id');
            $table->index(['workspace_id', 'ai_employee_id'], 'ai_logs_workspace_employee_index');
        });
    }

    public function down(): void
    {
        Schema::table('ai_logs', function (Blueprint $table): void {
            $table->dropIndex('ai_logs_workspace_employee_index');
            $table->dropColumn('ai_employee_id');
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ai_employee_id');
        });
    }
};
