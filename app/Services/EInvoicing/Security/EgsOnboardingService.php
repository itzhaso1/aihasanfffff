<?php

namespace App\Services\EInvoicing\Security;

use App\EInvoicing\Security\Exceptions\EgsOnboardingException;
use App\Enums\EInvoicing\EgsOnboardingStatus;
use App\Enums\EInvoicing\EInvoicingEnvironment;
use App\Models\EInvoicing\EgsOnboarding;
use App\Models\EInvoicing\EgsUnit;
use Illuminate\Database\UniqueConstraintViolationException;

final class EgsOnboardingService
{
    public function __construct(
        private readonly EgsUnitResolver $egsUnits,
    ) {}

    public function ensure(
        int $workspaceId,
        int $egsUnitId,
        EInvoicingEnvironment $environment,
    ): EgsOnboarding {
        $this->egsUnits->requireForWorkspace($egsUnitId, $workspaceId);

        $existing = EgsOnboarding::withoutGlobalScopes()
            ->where('egs_unit_id', $egsUnitId)
            ->where('environment', $environment->value)
            ->first();
        if ($existing) {
            $this->assertWorkspaceMatch($existing, $workspaceId);

            return $existing;
        }

        try {
            return EgsOnboarding::withoutGlobalScopes()->create([
                'workspace_id' => $workspaceId,
                'egs_unit_id' => $egsUnitId,
                'environment' => $environment,
                'status' => EgsOnboardingStatus::Unconfigured,
            ]);
        } catch (UniqueConstraintViolationException) {
            $retry = EgsOnboarding::withoutGlobalScopes()
                ->where('egs_unit_id', $egsUnitId)
                ->where('environment', $environment->value)
                ->first();
            if ($retry) {
                $this->assertWorkspaceMatch($retry, $workspaceId);

                return $retry;
            }

            throw new EgsOnboardingException(
                'Could not create onboarding record.',
                operation: 'ensure_onboarding',
                reason: 'create_collision',
            );
        }
    }

    public function requireForWorkspace(
        int $workspaceId,
        int $egsUnitId,
        EInvoicingEnvironment $environment,
    ): EgsOnboarding {
        $onboarding = EgsOnboarding::withoutGlobalScopes()
            ->where('egs_unit_id', $egsUnitId)
            ->where('environment', $environment->value)
            ->first();

        if ($onboarding === null) {
            throw new EgsOnboardingException(
                'Onboarding record was not found.',
                operation: 'resolve_onboarding',
                reason: 'missing_onboarding',
            );
        }

        $this->assertWorkspaceMatch($onboarding, $workspaceId);
        $this->egsUnits->requireForWorkspace($egsUnitId, $workspaceId);

        return $onboarding;
    }

    private function assertWorkspaceMatch(EgsOnboarding $onboarding, int $workspaceId): void
    {
        if ((int) $onboarding->workspace_id !== $workspaceId) {
            throw new EgsOnboardingException(
                'Onboarding does not belong to this workspace.',
                onboardingId: (int) $onboarding->id,
                operation: 'resolve_onboarding',
                reason: 'workspace_mismatch',
            );
        }

        $unit = EgsUnit::withoutGlobalScopes()->find($onboarding->egs_unit_id);
        if ($unit === null || (int) $unit->workspace_id !== $workspaceId) {
            throw new EgsOnboardingException(
                'EGS unit workspace does not match onboarding.',
                onboardingId: (int) $onboarding->id,
                operation: 'resolve_onboarding',
                reason: 'egs_workspace_mismatch',
            );
        }
    }
}
