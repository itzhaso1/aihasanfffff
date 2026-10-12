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
        if (! Schema::hasTable('finance_employees')) {
            Schema::create('finance_employees', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->string('employee_code', 40);
                $table->string('full_name');
                $table->string('job_title')->nullable();
                $table->decimal('basic_salary', 14, 2)->default(0);
                $table->date('hire_date')->nullable();
                $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->string('address')->nullable();
                $table->string('emergency_contact')->nullable();
                $table->text('notes')->nullable();
                $table->json('metadata')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['workspace_id', 'employee_code']);
                $table->index(['workspace_id', 'status']);
                $table->index(['workspace_id', 'full_name']);
            });
        }

        if (! Schema::hasTable('finance_employee_payroll_records')) {
            Schema::create('finance_employee_payroll_records', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('finance_employee_id')->constrained('finance_employees')->cascadeOnDelete();
                $table->date('period_start');
                $table->date('period_end');
                $table->decimal('basic_salary', 14, 2)->default(0);
                $table->decimal('allowances_total', 14, 2)->default(0);
                $table->decimal('deductions_total', 14, 2)->default(0);
                $table->decimal('gross_amount', 14, 2)->default(0);
                $table->decimal('net_amount', 14, 2)->default(0);
                $table->enum('payment_status', ['draft', 'pending', 'paid', 'partial', 'cancelled'])->default('draft');
                $table->timestamp('paid_at')->nullable();
                $table->text('notes')->nullable();
                $table->json('metadata')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(
                    ['workspace_id', 'finance_employee_id', 'period_start', 'period_end'],
                    'fin_emp_payroll_period_unique'
                );
                $table->index(['workspace_id', 'payment_status'], 'fin_emp_pay_ws_pay_status_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finance_employee_payroll_records');
        Schema::dropIfExists('finance_employees');
    }
};
