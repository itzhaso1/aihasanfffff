<?php

namespace Database\Factories\Finance;

use App\Models\Finance\FinanceExpense;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinanceExpense>
 */
class FinanceExpenseFactory extends Factory
{
    protected $model = FinanceExpense::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'expense_number' => 'EXP-'.fake()->unique()->numerify('######'),
            'expense_date' => now()->toDateString(),
            'description' => fake()->sentence(),
            'amount' => 100,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total' => 100,
            'currency' => 'SAR',
            'payment_method' => 'cash',
            'status' => 'draft',
        ];
    }
}
