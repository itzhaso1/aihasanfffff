<?php

namespace App\Services\EInvoicing\Security;

use App\EInvoicing\Security\Csr\CsrSubjectData;
use App\EInvoicing\Security\Csr\CsrGenerator;
use App\EInvoicing\Security\Exceptions\EgsOnboardingException;
use App\EInvoicing\Security\Exceptions\PrivateKeyStoreException;
use App\Enums\EInvoicing\EgsOnboardingStatus;
use App\Enums\EInvoicing\EInvoicingEnvironment;
use App\Models\EInvoicing\EgsOnboarding;
use App\Models\EInvoicing\EgsUnit;
use App\Models\EInvoicing\PrivateKeyReference;
use App\Models\Finance\FinanceSetting;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Generates a dev/test CSR and binds it to onboarding + {@see PrivateKeyReference}.
 * Does not return private key material.
 */
final class EgsCsrGenerationService
{
    public function __construct(
        private readonly EgsOnboardingService $onboarding,
        private readonly PrivateKeyReferenceService $keyReferences,
        private readonly PrivateKeyReferenceResolver $keyResolver,
        private readonly PrivateKeyStoreEnvironmentGuard $storeGuard,
        private readonly CsrGenerator $csrGenerator,
    ) {}

    public function generateForEgs(
        int $workspaceId,
        int $egsUnitId,
        EInvoicingEnvironment $environment,
    ): EgsOnboarding {
        $this->storeGuard->assertDevDriverAllowed('dev_memory');

        if ($environment === EInvoicingEnvironment::Production) {
            throw new EgsOnboardingException(
                'Production CSR generation is not available until a production key store is configured.',
                operation: 'csr_generate',
                reason: 'production_csr_not_implemented',
            );
        }

        $record = $this->onboarding->ensure($workspaceId, $egsUnitId, $environment);

        if (! $record->status->allowsCsrGeneration()) {
            throw new EgsOnboardingException(
                'CSR generation is not allowed for the current onboarding status.',
                onboardingId: (int) $record->id,
                operation: 'csr_generate',
                reason: 'status_not_allowed',
            );
        }

        if ($record->private_key_reference_id !== null || $record->csr_pem !== null) {
            throw new EgsOnboardingException(
                'An existing CSR or key reference must not be overwritten. Use an explicit rotation workflow in a later phase.',
                onboardingId: (int) $record->id,
                operation: 'csr_generate',
                reason: 'csr_already_present',
            );
        }

        $subject = $this->buildSubject($workspaceId, $egsUnitId);

        try {
            return DB::transaction(function () use ($record, $workspaceId, $egsUnitId, $environment, $subject): EgsOnboarding {
                $locked = EgsOnboarding::withoutGlobalScopes()->lockForUpdate()->find($record->id);
                if ($locked === null) {
                    throw new EgsOnboardingException(
                        'Onboarding record disappeared during CSR generation.',
                        operation: 'csr_generate',
                        reason: 'missing_onboarding',
                    );
                }

                if (! $locked->status->allowsCsrGeneration()) {
                    throw new EgsOnboardingException(
                        'CSR generation is not allowed for the current onboarding status.',
                        onboardingId: (int) $locked->id,
                        operation: 'csr_generate',
                        reason: 'status_not_allowed',
                    );
                }

                $generated = $this->csrGenerator->generate($subject);

                $reference = $this->keyReferences->createActiveDevReference(
                    $workspaceId,
                    $egsUnitId,
                    $environment,
                    $generated['public_key_fingerprint'],
                    $generated['private_key_pem'],
                );

                $this->keyResolver->resolve(
                    $workspaceId,
                    (int) $reference->id,
                    $environment,
                    $egsUnitId,
                );

                $csrFingerprint = hash('sha256', $generated['csr_pem']);

                $locked->private_key_reference_id = $reference->id;
                $locked->csr_pem = $generated['csr_pem'];
                $locked->csr_fingerprint_sha256 = $csrFingerprint;
                $locked->csr_generated_at = now();
                $locked->status = EgsOnboardingStatus::CsrGenerated;
                $locked->failure_code = null;
                $locked->failure_message = null;
                $locked->save();

                return $locked->fresh(['privateKeyReference']) ?? $locked;
            });
        } catch (EgsOnboardingException|PrivateKeyStoreException $exception) {
            $this->markFailed($record, $exception);

            throw $exception;
        } catch (Throwable $exception) {
            $wrapped = new EgsOnboardingException(
                'CSR generation failed.',
                onboardingId: (int) $record->id,
                operation: 'csr_generate',
                reason: 'unexpected',
            );
            $this->markFailed($record, $wrapped);

            throw $wrapped;
        }
    }

    private function buildSubject(int $workspaceId, int $egsUnitId): CsrSubjectData
    {
        $unit = EgsUnit::withoutGlobalScopes()->findOrFail($egsUnitId);
        $settings = FinanceSetting::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->first();

        return new CsrSubjectData(
            commonName: 'hasem-dev-egs-'.$egsUnitId,
            organizationName: $settings?->company_name ?: 'HASEM Dev Organization',
            organizationalUnitName: $unit->name,
            countryName: $settings?->country_code ?: 'SA',
        );
    }

    private function markFailed(EgsOnboarding $record, Throwable $exception): void
    {
        EgsOnboarding::withoutGlobalScopes()
            ->whereKey($record->id)
            ->update([
                'status' => EgsOnboardingStatus::Failed->value,
                'failure_code' => $exception instanceof EgsOnboardingException || $exception instanceof PrivateKeyStoreException
                    ? ($exception->reason ?? 'failed')
                    : 'failed',
                'failure_message' => $this->safeFailureMessage($exception),
            ]);
    }

    private function safeFailureMessage(Throwable $exception): string
    {
        $message = $exception->getMessage();
        if (str_contains($message, 'BEGIN') && str_contains($message, 'KEY')) {
            return 'CSR generation failed.';
        }

        return mb_substr($message, 0, 500);
    }
}
