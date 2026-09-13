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
        $supportsAlterForeignKeys = Schema::getConnection()->getDriverName() !== 'sqlite';

        if (
            Schema::hasTable('finance_payroll_adjustments')
            && Schema::hasTable('finance_employees')
            && ! Schema::hasColumn('finance_payroll_adjustments', 'finance_employee_id')
        ) {
            Schema::table('finance_payroll_adjustments', function (Blueprint $table): void {
                $table->unsignedBigInteger('finance_employee_id')->nullable()->after('user_id');
                $table->index(['workspace_id', 'finance_employee_id'], 'fin_pay_adj_ws_fin_emp_idx');
            });

            if ($supportsAlterForeignKeys) {
                Schema::table('finance_payroll_adjustments', function (Blueprint $table): void {
                    $table->foreign('finance_employee_id', 'fin_pay_adj_fin_emp_fk')
                        ->references('id')
                        ->on('finance_employees')
                        ->nullOnDelete();
                });
            }
        }

        if (
            Schema::hasTable('finance_salary_advances')
            && Schema::hasTable('finance_employees')
            && ! Schema::hasColumn('finance_salary_advances', 'finance_employee_id')
        ) {
            Schema::table('finance_salary_advances', function (Blueprint $table): void {
                $table->unsignedBigInteger('finance_employee_id')->nullable()->after('user_id');
                $table->index(['workspace_id', 'finance_employee_id'], 'fin_sal_adv_ws_fin_emp_idx');
            });

            if ($supportsAlterForeignKeys) {
                Schema::table('finance_salary_advances', function (Blueprint $table): void {
                    $table->foreign('finance_employee_id', 'fin_sal_adv_fin_emp_fk')
                        ->references('id')
                        ->on('finance_employees')
                        ->nullOnDelete();
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $supportsAlterForeignKeys = Schema::getConnection()->getDriverName() !== 'sqlite';

        if (Schema::hasTable('finance_payroll_adjustments') && Schema::hasColumn('finance_payroll_adjustments', 'finance_employee_id')) {
            Schema::table('finance_payroll_adjustments', function (Blueprint $table) use ($supportsAlterForeignKeys): void {
                if ($supportsAlterForeignKeys) {
                    $table->dropForeign('fin_pay_adj_fin_emp_fk');
                }
                $table->dropIndex('fin_pay_adj_ws_fin_emp_idx');
                $table->dropColumn('finance_employee_id');
            });
        }

        if (Schema::hasTable('finance_salary_advances') && Schema::hasColumn('finance_salary_advances', 'finance_employee_id')) {
            Schema::table('finance_salary_advances', function (Blueprint $table) use ($supportsAlterForeignKeys): void {
                if ($supportsAlterForeignKeys) {
                    $table->dropForeign('fin_sal_adv_fin_emp_fk');
                }
                $table->dropIndex('fin_sal_adv_ws_fin_emp_idx');
                $table->dropColumn('finance_employee_id');
            });
        }
    }
};
