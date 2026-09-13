<?php

namespace Database\Factories\Finance;

use App\Models\Finance\FinanceBillingSchedule;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinanceBillingSchedule>
 */
class FinanceBillingScheduleFactory extends Factory
{
    protected $model = FinanceBillingSchedule::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'title' => 'Monthly billing',
            'status' => FinanceBillingSchedule::STATUS_DRAFT,
            'frequency' => 'monthly',
            'interval_count' => 1,
            'generated_count' => 0,
            'amount' => 100,
            'currency' => 'SAR',
            'start_date' => now()->toDateString(),
            'next_run_on' => now()->toDateString(),
            'auto_issue' => false,
            'invoice_type' => 'sales',
        ];
    }
}
