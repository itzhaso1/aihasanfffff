<?php

namespace Database\Factories;

use App\Models\DiningTable;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DiningTable>
 */
class DiningTableFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => 'Table '.fake()->unique()->numberBetween(1, 999),
            'status' => 'available',
            'qr_token' => Str::random(48),
        ];
    }
}
