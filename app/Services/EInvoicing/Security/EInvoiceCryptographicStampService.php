<?php

namespace App\Services\EInvoicing\Security;

use App\EInvoicing\EInvoiceDocument;
use App\EInvoicing\Security\CertificateFingerprint;
use App\EInvoicing\Security\CryptographicStamp;
use App\EInvoicing\Security\CryptographicStampSigner;
use App\EInvoicing\Security\Exceptions\CryptographicStampException;
use App\EInvoicing\Security\Harness\CryptographicProfileGuard;
use App\EInvoicing\Security\InvoiceHash;
use App\EInvoicing\Security\SigningAlgorithm;
use App\EInvoicing\Security\SigningInput;
use App\EInvoicing\Security\StampStatus;
use App\Models\EInvoicing\EInvoiceCertificate;
use App\Models\EInvoicing\EInvoiceCryptographicStamp;
use App\Models\EInvoicing\EInvoiceSecurityRecord;
use App\Models\Workspace;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

/**
 * Applies a local cryptographic stamp after Phase 7 ICV/PIH/hash exist.
 *
 * Does not allocate ICV, change PIH, recompute invoice hash, encode QR,
 * emit XAdES, or contact ZATCA.
 */
final class EInvoiceCryptographicStampService
{
    public function __construct(
        private readonly CryptographicStampSigner $signer,
        private readonly CertificateRegistry $certificates,
        private readonly AuditLogService $audit,
    ) {}

    public function stamp(
        EInvoiceDocument $document,
        EInvoiceSecurityRecord $security,
        int $certificateId,
    ): CryptographicStamp {
        $this->assertSignerBoundary();
        $this->assertWorkspace($document, $security);
        $this->assertNoPrivateKeyColumns();
        $workspace = Workspace::withoutGlobalScopes()->find($document->workspaceId);
        if ($workspace instanceof Workspace) {
            app(WorkspaceContext::class)->set($workspace);
        }

        $certificate = $this->certificates->requireForEgs(
            $document->workspaceId,
            (int) $security->egs_unit_id,
            $certificateId,
        );
        $parsed = $this->certificates->parsed($certificate);
        $hash = InvoiceHash::fromString((string) $security->invoice_hash);

        $existing = $this->existingStamp((int) $security->e_invoice_document_id);
        if ($existing) {
            return $this->reuseOrReject($existing, $hash, $certificate, $document);
        }

        $input = new SigningInput(
            invoiceHash: $hash,
            workspaceId: $document->workspaceId,
            egsUnitId: (int) $security->egs_unit_id,
            eInvoiceDocumentId: (int) $security->e_invoice_document_id,
            documentIdentity: $this->identity($document),
            certificate: $parsed,
        );

        $produced = $this->signer->sign($input);
        if ($produced->isProductionIdentity() || $this->signer->isProductionIdentity()) {
            throw new CryptographicStampException(
                'A production ZATCA identity is not configured. Phase 9 will not claim production signing.',
                documentIdentity: $this->identity($document),
                operation: 'stamp',
                reason: 'production_identity_forbidden',
            );
        }
        if ($produced->status !== StampStatus::TestSigned) {
            throw new CryptographicStampException(
                'Signer did not produce a test stamp. Deferred/unsigned results are not persisted as signed.',
                documentIdentity: $this->identity($document),
                operation: 'stamp',
                reason: 'unsigned_result',
            );
        }
        if (! $produced->invoiceHash->equals($hash)) {
            throw new CryptographicStampException(
                'Stamp invoice hash does not match the persisted Phase 7 hash.',
                documentIdentity: $this->identity($document),
                operation: 'stamp',
                reason: 'hash_mismatch',
            );
        }

        try {
            $record = EInvoiceCryptographicStamp::withoutGlobalScopes()->create([
                'workspace_id' => $document->workspaceId,
                'egs_unit_id' => $security->egs_unit_id,
                'e_invoice_document_id' => $security->e_invoice_document_id,
                'e_invoice_certificate_id' => $certificate->id,
                'invoice_hash' => $hash->value(),
                'signature_algorithm' => $produced->signatureAlgorithm,
                'signature_value' => $produced->signatureDerBase64,
                'public_key_spki' => base64_encode($produced->publicKeySpkiDer),
                'signed_input_identifier' => $produced->signedInputIdentifier,
                'stamp_status' => StampStatus::TestSigned->value,
                'finalized_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = $this->existingStamp((int) $security->e_invoice_document_id);
            if ($existing) {
                return $this->reuseOrReject($existing, $hash, $certificate, $document);
            }

            throw new CryptographicStampException(
                'Cryptographic stamp persist collided.',
                documentIdentity: $this->identity($document),
                operation: 'stamp',
                reason: 'persist_collision',
            );
        }

        $stamp = $this->fromRecord($record, $parsed->fingerprint);
        $this->audit->log(
            action: 'e_invoice.stamp.test_signed',
            entityType: 'e_invoice_cryptographic_stamp',
            entityId: (int) $record->id,
            newValues: [
                'e_invoice_document_id' => $record->e_invoice_document_id,
                'e_invoice_certificate_id' => $record->e_invoice_certificate_id,
                'invoice_hash' => $record->invoice_hash,
                'fingerprint' => $parsed->fingerprint->value(),
            ],
            workspaceId: $document->workspaceId,
        );

        return $stamp;
    }

    public function existingForDocument(int $eInvoiceDocumentId): ?CryptographicStamp
    {
        $record = $this->existingStamp($eInvoiceDocumentId);
        if ($record === null) {
            return null;
        }

        $certificate = EInvoiceCertificate::withoutGlobalScopes()->find((int) $record->e_invoice_certificate_id);
        if ($certificate === null) {
            throw new CryptographicStampException(
                'Persisted stamp references a missing certificate.',
                operation: 'load_stamp',
                reason: 'missing_certificate',
            );
        }

        return $this->fromRecord(
            $record,
            CertificateFingerprint::fromSha256Hex((string) $certificate->fingerprint_sha256),
        );
    }

    private function reuseOrReject(
        EInvoiceCryptographicStamp $existing,
        InvoiceHash $hash,
        EInvoiceCertificate $certificate,
        EInvoiceDocument $document,
    ): CryptographicStamp {
        if ((string) $existing->invoice_hash !== $hash->value()) {
            throw new CryptographicStampException(
                'Persisted stamp invoice hash does not match the Phase 7 security record.',
                documentIdentity: $this->identity($document),
                operation: 'stamp_reuse',
                reason: 'hash_mismatch',
            );
        }

        if ((int) $existing->e_invoice_certificate_id !== (int) $certificate->id) {
            throw new CryptographicStampException(
                'Document already has a finalized stamp for a different certificate. The signature will not be replaced.',
                documentIdentity: $this->identity($document),
                operation: 'stamp_reuse',
                reason: 'certificate_mismatch',
            );
        }

        if (blank($existing->signature_value) || blank($existing->public_key_spki)) {
            throw new CryptographicStampException(
                'Persisted cryptographic stamp is missing signature or public-key material.',
                documentIdentity: $this->identity($document),
                operation: 'stamp_reuse',
                reason: 'corrupt_stamp',
            );
        }

        return $this->fromRecord(
            $existing,
            CertificateFingerprint::fromSha256Hex((string) $certificate->fingerprint_sha256),
        );
    }

    private function fromRecord(EInvoiceCryptographicStamp $record, CertificateFingerprint $fingerprint): CryptographicStamp
    {
        $spki = base64_decode((string) $record->public_key_spki, true);
        if ($spki === false || $spki === '') {
            throw new CryptographicStampException(
                'Persisted public key SPKI is not valid Base64.',
                operation: 'load_stamp',
                reason: 'corrupt_public_key',
            );
        }

        return new CryptographicStamp(
            status: $record->stamp_status instanceof StampStatus
                ? $record->stamp_status
                : StampStatus::from((string) $record->stamp_status),
            invoiceHash: InvoiceHash::fromString((string) $record->invoice_hash),
            signatureDerBase64: (string) $record->signature_value,
            publicKeySpkiDer: $spki,
            signatureAlgorithm: (string) $record->signature_algorithm,
            curve: SigningAlgorithm::TEST_CURVE,
            certificateFingerprint: $fingerprint,
            certificateId: (int) $record->e_invoice_certificate_id,
            signedInputIdentifier: (string) $record->signed_input_identifier,
            productionIdentity: false,
        );
    }

    private function existingStamp(int $documentId): ?EInvoiceCryptographicStamp
    {
        return EInvoiceCryptographicStamp::withoutGlobalScopes()
            ->where('e_invoice_document_id', $documentId)
            ->first();
    }

    private function assertSignerBoundary(): void
    {
        (new CryptographicProfileGuard(app()))->assertSignerMayRun($this->signer);

        if ($this->signer instanceof DeferredCryptographicStampSigner) {
            throw new CryptographicStampException(
                'Default signer is deferred. Production CSID provisioning is not implemented. Test signing requires an explicit TestCryptographicStampSigner.',
                operation: 'stamp',
                reason: 'stamp_deferred',
            );
        }
    }

    private function assertWorkspace(EInvoiceDocument $document, EInvoiceSecurityRecord $security): void
    {
        if ((int) $security->workspace_id !== $document->workspaceId) {
            throw new CryptographicStampException(
                'Security record workspace does not match the electronic document.',
                documentIdentity: $this->identity($document),
                operation: 'stamp',
                reason: 'workspace_mismatch',
            );
        }
    }

    private function assertNoPrivateKeyColumns(): void
    {
        foreach (['e_invoice_certificates', 'e_invoice_cryptographic_stamps'] as $table) {
            foreach (['private_key', 'private_key_pem', 'private_key_password', 'secret_key'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    throw new CryptographicStampException(
                        'Private key columns are forbidden on cryptographic persistence tables.',
                        operation: 'stamp',
                        reason: 'private_key_column',
                    );
                }
            }
        }
    }

    private function identity(EInvoiceDocument $document): string
    {
        return $document->sourceType.':'.$document->sourceId.':snapshot:'.$document->sourceSnapshotId;
    }
}
