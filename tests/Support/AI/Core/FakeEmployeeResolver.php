<?php

declare(strict_types=1);

namespace Tests\Support\AI\Core;

use App\AI\Core\AiRequest;
use App\AI\Core\Contracts\EmployeeResolver;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Core\Prompt\PromptLayer;
use App\AI\Core\Prompt\PromptSection;
use Throwable;

final class FakeEmployeeResolver implements EmployeeResolver
{
    public ?Throwable $throw = null;

    /** @var list<AiRequest> */
    public array $requests = [];

    public ResolvedEmployee $employee;

    public function __construct()
    {
        $this->employee = new ResolvedEmployee(
            employeeId: 7,
            configVersion: 'cfg-3',
            skillKeys: ['sales', 'product_search'],
            sections: [new PromptSection(PromptLayer::Employee, 'employee.identity', 'EMPLOYEE-LAYER', 'employee:7', 'cfg-3')],
        );
    }

    public function resolve(AiRequest $request): ResolvedEmployee
    {
        $this->requests[] = $request;
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->employee;
    }
}
