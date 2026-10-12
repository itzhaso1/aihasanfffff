<?php

declare(strict_types=1);

namespace Tests\Support\AI\Employees;

use App\AI\Employees\EmployeeFactory;
use App\AI\Employees\EmployeeService;
use App\AI\Employees\Models\AiEmployee;
use App\Models\User;
use App\Models\Workspace;

trait CreatesEmployees
{
    protected function workspace(string $name = 'Employees WS'): Workspace
    {
        $owner = User::factory()->create();

        return Workspace::factory()->create(['owner_user_id' => $owner->id, 'type' => 'company', 'name' => $name]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function employee(Workspace $workspace, array $overrides = []): AiEmployee
    {
        $draft = app(EmployeeFactory::class)->fromArray([
            'name' => 'Nora',
            'role' => 'sales_agent',
            'persona' => ['language' => 'ar', 'dialect' => 'saudi'],
            'skills' => ['sales'],
            ...$overrides,
        ]);

        return app(EmployeeService::class)->create($workspace, $draft);
    }
}
