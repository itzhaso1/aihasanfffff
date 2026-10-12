<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Worker\WorkforceCapabilityDefinition;
use Illuminate\Database\Seeder;

class WorkerCapabilitySeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('worker_capabilities', []) as $definition) {
            WorkforceCapabilityDefinition::query()->updateOrCreate(
                ['capability_key' => $definition['capability_key']],
                [
                    'display_name' => $definition['display_name'],
                    'description' => $definition['description'],
                    'is_active' => true,
                    'sort_order' => $definition['sort_order'],
                ],
            );
        }
    }
}
