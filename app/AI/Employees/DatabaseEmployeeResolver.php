<?php

declare(strict_types=1);

namespace App\AI\Employees;

use App\AI\Core\AiRequest;
use App\AI\Core\Contracts\EmployeeResolver;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Employees\Models\AiEmployee;
use App\AI\Employees\Resolution\ConversationEmployeeSource;
use App\AI\Employees\Resolution\DefaultEmployeeSource;
use App\AI\Employees\Resolution\EmployeeCandidateSource;
use App\AI\Employees\Resolution\NoEmployeeAvailable;
use App\AI\Employees\Resolution\RequestedEmployeeSource;

/**
 * Core employee resolver backed by the employee tables. Sources are asked in order:
 * explicit request, conversation binding, (channel assignment in a later phase), default.
 * A candidate is used only when it is an active employee of the request workspace
 * (explicit requests may name an inactive one in preview and evaluation). Read-only.
 */
final class DatabaseEmployeeResolver implements EmployeeResolver
{
    /** @var list<EmployeeCandidateSource> */
    private readonly array $sources;

    /**
     * @param  ?list<EmployeeCandidateSource>  $sources
     */
    public function __construct(
        private readonly EmployeeRepository $employees,
        private readonly EmployeePromptRenderer $renderer,
        ?array $sources = null,
    ) {
        $this->sources = $sources ?? [
            new RequestedEmployeeSource,
            new ConversationEmployeeSource,
            new DefaultEmployeeSource($employees),
        ];
    }

    public function resolve(AiRequest $request): ResolvedEmployee
    {
        foreach ($this->sources as $source) {
            $employee = $this->usable($request, $source);
            if ($employee !== null) {
                return new ResolvedEmployee(
                    employeeId: (int) $employee->id,
                    configVersion: (string) $employee->config_version,
                    skillKeys: $employee->skillKeys(),
                    sections: $this->renderer->sections($employee),
                );
            }
        }

        throw NoEmployeeAvailable::forWorkspace();
    }

    private function usable(AiRequest $request, EmployeeCandidateSource $source): ?AiEmployee
    {
        $id = $source->candidate($request);
        if ($id === null) {
            return null;
        }
        $employee = $this->employees->find($request->workspaceId, $id);
        if ($employee === null) {
            return null;
        }
        $allowInactive = $source->allowsInactiveInSandbox() && $request->runMode->isSandboxed();

        return $employee->isActive() || $allowInactive ? $employee : null;
    }
}
