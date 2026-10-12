<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contracts') && ! Schema::hasColumn('contracts', 'project_id') && Schema::hasTable('finance_projects')) {
            Schema::table('contracts', function (Blueprint $table): void {
                $table->foreignId('project_id')->nullable()->after('customer_id')->constrained('finance_projects')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('contracts', 'project_id')) {
            Schema::table('contracts', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('project_id');
            });
        }
    }
};
