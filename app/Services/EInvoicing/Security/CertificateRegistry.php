<?php

namespace App\Services\EInvoicing\Security;

use App\EInvoicing\Security\CryptographicCertificate;
use App\EInvoicing\Security\CryptographicIdentity;
use App\EInvoicing\Security\Exceptions\CertificateException;
use App\EInvoicing\Security\StampStatus;
use App\EInvoicing\Security\X509CertificateParser;
use App\Models\EInvoicing\EInvoiceCertificate;
use App\Models\Workspace;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Symfony\Component\Uid\Uuid;

/**
 * Persists public certificate metadata only. Never stores private keys.
 */
final class CertificateRegistry
{
    public function __construct(
        private readonly X509CertificateParser $parser,
        private readonly EgsUnitResolver $egsUnits,
    ) {}

    public function importPublicCertificate(
        int $workspaceId,
        int $egsUnitId,
        string $pem,
        bool $testFixture = false,
    ): EInvoiceCertificate {
        $this->assertNoPrivateKeyColumnIntent($pem);
        $unit = $this->egsUnits->requireForWorkspace($egsUnitId, $workspaceId);
        $workspace = Workspace::withoutGlobalScopes()->find($workspaceId);
        if ($workspace instanceof Workspace) {
            app(WorkspaceContext::class)->set($workspace);
        }
        $parsed = $this->parser->parsePem($pem, $testFixture);

        $existing = EInvoiceCertificate::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('fingerprint_sha256', $parsed->fingerprint->value())
            ->first();
        if ($existing) {
            if ((int) $existing->egs_unit_id !== (int) $unit->id) {
                throw new CertificateException(
                    'Certificate fingerprint already belongs to another EGS unit.',
                    sequenceIdentity: 'egs:'.$unit->id,
                    operation: 'import_certificate',
                    reason: 'fingerprint_egs_mismatch',
                );
            }

            return $existing;
        }

        try {
            return EInvoiceCertificate::withoutGlobalScopes()->create([
                'workspace_id' => $workspaceId,
                'egs_unit_id' => $unit->id,
                'certificate_identifier' => (string) Uuid::v4(),
                'serial_number' => $parsed->serialNumber,
                'subject' => $parsed->subject,
                'issuer' => $parsed->issuer,
                'not_before' => $parsed->notBefore,
                'not_after' => $parsed->notAfter,
                'fingerprint_sha256' => $parsed->fingerprint->value(),
                'public_certificate' => $parsed->publicCertificatePem,
                'public_key_algorithm' => $parsed->publicKey->algorithm,
                'signature_algorithm' => $parsed->signatureAlgorithm,
                'curve' => $parsed->publicKey->curve,
                'key_length_bits' => $parsed->publicKey->bitLength,
                'status' => EInvoiceCertificate::STATUS_IMPORTED,
                'test_fixture' => $testFixture,
            ]);
        } catch (UniqueConstraintViolationException) {
            $retry = EInvoiceCertificate::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('fingerprint_sha256', $parsed->fingerprint->value())
                ->first();
            if ($retry) {
                return $retry;
            }

            throw new CertificateException(
                'Certificate import collided.',
                sequenceIdentity: 'egs:'.$unit->id,
                operation: 'import_certificate',
                reason: 'import_collision',
            );
        }
    }

    public function requireForEgs(int $workspaceId, int $egsUnitId, int $certificateId): EInvoiceCertificate
    {
        $this->egsUnits->requireForWorkspace($egsUnitId, $workspaceId);

        $certificate = EInvoiceCertificate::withoutGlobalScopes()->find($certificateId);
        if ($certificate === null) {
            throw new CertificateException(
                'Certificate was not found.',
                sequenceIdentity: 'egs:'.$egsUnitId,
                operation: 'resolve_certificate',
                reason: 'missing_certificate',
            );
        }

        if ((int) $certificate->workspace_id !== $workspaceId || (int) $certificate->egs_unit_id !== $egsUnitId) {
            throw new CertificateException(
                'Certificate does not belong to the requested workspace EGS unit.',
                sequenceIdentity: 'egs:'.$egsUnitId,
                operation: 'resolve_certificate',
                reason: 'certificate_egs_mismatch',
            );
        }

        return $certificate;
    }

    public function identityFromRecord(EInvoiceCertificate $record): CryptographicIdentity
    {
        return new CryptographicIdentity(
            workspaceId: (int) $record->workspace_id,
            egsUnitId: (int) $record->egs_unit_id,
            certificate: $this->parser->parsePem(
                (string) $record->public_certificate,
                (bool) $record->test_fixture,
            ),
            usableFor: $record->test_fixture ? StampStatus::TestSigned : StampStatus::Unsigned,
        );
    }

    public function parsed(EInvoiceCertificate $record): CryptographicCertificate
    {
        return $this->parser->parsePem((string) $record->public_certificate, (bool) $record->test_fixture);
    }

    private function assertNoPrivateKeyColumnIntent(string $pem): void
    {
        if (preg_match('/-----BEGIN ([A-Z0-9 ]+)?PRIVATE KEY-----/', $pem) === 1) {
            throw new CertificateException(
                'Private keys cannot be imported into certificate persistence.',
                operation: 'import_certificate',
                reason: 'private_key_rejected',
            );
        }
    }
}
