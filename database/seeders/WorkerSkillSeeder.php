<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Worker\WorkerSkill;
use Illuminate\Database\Seeder;

class WorkerSkillSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('worker_professional.skills', []) as $definition) {
            WorkerSkill::query()->updateOrCreate(
                ['skill_key' => $definition['skill_key']],
                [
                    'display_name' => $definition['display_name'],
                    'sort_order' => $definition['sort_order'],
                ],
            );
        }
    }
}
