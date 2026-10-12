<?php

namespace Database\Factories;

use App\Models\DiningTable;
use App\Models\TableSession;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TableSession>
 */
class TableSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'dining_table_id' => DiningTable::factory(),
            'status' => 'open',
            'opened_at' => now()->subMinutes(fake()->numberBetween(5, 180)),
            'closed_at' => null,
        ];
    }
}
