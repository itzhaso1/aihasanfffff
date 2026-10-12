<?php

namespace Database\Factories\Finance;

use App\Models\Finance\FinanceTreasuryAccount;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinanceTreasuryAccount>
 */
class FinanceTreasuryAccountFactory extends Factory
{
    protected $model = FinanceTreasuryAccount::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => 'Cash',
            'type' => 'cash',
            'currency' => 'SAR',
            'opening_balance' => 0,
            'current_balance' => 0,
            'is_active' => true,
        ];
    }
}
