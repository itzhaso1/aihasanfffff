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
        $hasFinanceRole = Schema::hasColumn('finance_employee_profiles', 'finance_role');
        $hasNotes = Schema::hasColumn('finance_employee_profiles', 'notes');

        Schema::table('finance_employee_profiles', function (Blueprint $table) use ($hasFinanceRole, $hasNotes): void {
            if (! $hasFinanceRole) {
                $table->string('finance_role')->nullable()->after('user_id');
            }

            if (! $hasNotes) {
                $table->text('notes')->nullable()->after('default_deductions');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $hasFinanceRole = Schema::hasColumn('finance_employee_profiles', 'finance_role');
        $hasNotes = Schema::hasColumn('finance_employee_profiles', 'notes');

        Schema::table('finance_employee_profiles', function (Blueprint $table) use ($hasFinanceRole, $hasNotes): void {
            if ($hasFinanceRole) {
                $table->dropColumn('finance_role');
            }

            if ($hasNotes) {
                $table->dropColumn('notes');
            }
        });
    }
};
