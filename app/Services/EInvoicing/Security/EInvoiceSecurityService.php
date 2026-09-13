<?php

namespace App\Services\EInvoicing\Security;

use App\EInvoicing\EInvoiceDocument;
use App\EInvoicing\Security\CanonicalXml;
use App\EInvoicing\Security\EInvoiceSecurityArtifact;
use App\EInvoicing\Security\Exceptions\EInvoiceSecurityException;
use App\EInvoicing\Security\Exceptions\SecurityChainException;
use App\EInvoicing\Security\Icv;
use App\EInvoicing\Security\InvoiceHash;
use App\EInvoicing\Security\Pih;
use App\EInvoicing\Xml\GeneratedEInvoiceXml;
use App\Enums\EInvoicing\ComplianceStatus;
use App\Enums\EInvoicing\ElectronicDocumentKind;
use App\Enums\EInvoicing\SecurityStatus;
use App\Models\EInvoicing\EInvoiceDocumentRecord;
use App\Models\EInvoicing\EInvoiceSecurityRecord;
use App\Services\Audit\AuditLogService;
use App\Services\EInvoicing\EInvoiceXmlGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Local e-invoice security chain:
 * EInvoiceDocument → generated XML → ICV/PIH enrich → canonicalize → hash → persist.
 *
 * Does not contact ZATCA/FATOORA, generate QR, or query live Finance/POS tables.
 */
final class EInvoiceSecurityService
{
    private const MAX_UNIQUE_RETRIES = 8;

    public function __construct(
        private readonly EInvoiceXmlGenerator $xmlGenerator,
        private readonly SecurityXmlEnricher $enricher,
        private readonly InvoiceHashService $hashService,
        private readonly EgsUnitResolver $egsUnits,
        private readonly IcvAllocator $allocator,
        private readonly AuditLogService $audit,
    ) {}

    public function generate(
        EInvoiceDocument $document,
        ?GeneratedEInvoiceXml $xml = null,
        ?int $egsUnitId = null,
    ): EInvoiceSecurityArtifact {
        $identity = $this->documentIdentity($document);

        try {
            $this->assertEligible($document);
            $record = $this->requirePersistedDocument($document);
            $xml ??= $this->xmlGenerator->generate($document);
            $this->assertXmlMatchesDocument($document, $xml);
            $sourceDigest = $this->hashService->sourceDigest($xml->xml);

            $existing = $this->existingRecord((int) $record->id);
            if ($existing) {
                return $this->reuseOrReject($existing, $xml, $sourceDigest, $identity);
            }

            $unit = $egsUnitId === null
                ? $this->egsUnits->defaultForWorkspace($document->workspaceId)
                : $this->egsUnits->requireForWorkspace($egsUnitId, $document->workspaceId);

            return $this->allocateAndPersist($document, $record, $xml, $sourceDigest, (int) $unit->id, $identity);
        } catch (EInvoiceSecurityException $exception) {
            $this->auditFailure($document, $exception);
            throw $exception;
        } catch (Throwable $exception) {
            $this->auditFailure($document, new EInvoiceSecurityException(
                'Security generation failed.',
                documentIdentity: $identity,
                operation: 'generate',
                reason: 'unexpected',
            ));
            throw $exception;
        }
    }

    private function allocateAndPersist(
        EInvoiceDocument $document,
        EInvoiceDocumentRecord $record,
        GeneratedEInvoiceXml $xml,
        string $sourceDigest,
        int $egsUnitId,
        string $identity,
    ): EInvoiceSecurityArtifact {
        $attempt = 0;

        while ($attempt < self::MAX_UNIQUE_RETRIES) {
            $attempt++;

            try {
                $artifact = DB::transaction(function () use ($document, $record, $xml, $sourceDigest, $egsUnitId, $identity): EInvoiceSecurityArtifact {
                    $existing = $this->existingRecord((int) $record->id);
                    if ($existing) {
                        return $this->artifactFromExisting($existing, $xml, $identity, reused: true);
                    }

                    $locked = $this->allocator->lock($egsUnitId);
                    if ((int) $locked->workspace_id !== $document->workspaceId) {
                        throw new SecurityChainException(
                            'EGS sequence workspace does not match the document workspace.',
                            documentIdentity: $identity,
                            sequenceIdentity: 'egs:'.$locked->uuid,
                            operation: 'allocate',
                            reason: 'workspace_mismatch',
                        );
                    }

                    $icv = $this->allocator->nextIcv($locked);
                    $pih = $this->allocator->previousHash($locked);
                    $enriched = $this->enricher->enrich($xml, $icv, $pih);
                    $canonical = $this->hashService->canonicalize($enriched, $identity);
                    $hash = $this->hashService->hash($enriched, $identity);

                    $persisted = EInvoiceSecurityRecord::withoutGlobalScopes()->create([
                        'workspace_id' => $document->workspaceId,
                        'egs_unit_id' => $locked->id,
                        'e_invoice_document_id' => $record->id,
                        'icv' => $icv->value(),
                        'pih' => $pih->value(),
                        'invoice_hash' => $hash->value(),
                        'hash_algorithm' => $this->hashService->algorithm(),
                        'canonicalization_method' => $this->hashService->canonicalizationMethod(),
                        'source_xml_digest' => $sourceDigest,
                        'security_status' => SecurityStatus::Generated->value,
                        'finalized_at' => now(),
                    ]);

                    $this->allocator->commit($locked, $icv, $hash);
                    $this->markDocumentGenerated($record);

                    return $this->artifact($persisted, $canonical, $enriched, reused: false);
                });

                $this->auditSuccess($artifact, $artifact->reused);

                return $artifact;
            } catch (UniqueConstraintViolationException) {
                $existing = $this->existingRecord((int) $record->id);
                if ($existing) {
                    return $this->reuseOrReject($existing, $xml, $sourceDigest, $identity);
                }

                if ($attempt >= self::MAX_UNIQUE_RETRIES) {
                    throw new SecurityChainException(
                        'ICV allocation collided repeatedly for this EGS sequence.',
                        documentIdentity: $identity,
                        sequenceIdentity: 'egs:'.$egsUnitId,
                        operation: 'allocate',
                        reason: 'icv_collision',
                    );
                }
            }
        }

        throw new SecurityChainException(
            'ICV allocation did not complete.',
            documentIdentity: $identity,
            sequenceIdentity: 'egs:'.$egsUnitId,
            operation: 'allocate',
            reason: 'exhausted_retries',
        );
    }

    private function reuseOrReject(
        EInvoiceSecurityRecord $existing,
        GeneratedEInvoiceXml $xml,
        string $sourceDigest,
        string $identity,
    ): EInvoiceSecurityArtifact {
        if ($existing->source_xml_digest !== $sourceDigest) {
            throw new SecurityChainException(
                'Electronic document XML changed after security artifacts were finalized. ICV/PIH/hash will not be reused for mutated input.',
                documentIdentity: $identity,
                sequenceIdentity: 'egs:'.$existing->egs_unit_id,
                operation: 'idempotent_reuse',
                reason: 'xml_mutated',
            );
        }

        $artifact = $this->artifactFromExisting($existing, $xml, $identity, reused: true);
        $this->auditSuccess($artifact, reused: true);

        return $artifact;
    }

    private function artifactFromExisting(
        EInvoiceSecurityRecord $existing,
        GeneratedEInvoiceXml $xml,
        string $identity,
        bool $reused,
    ): EInvoiceSecurityArtifact {
        $icv = Icv::fromInt((int) $existing->icv);
        $pih = Pih::fromString((string) $existing->pih);
        $enriched = $this->enricher->enrich($xml, $icv, $pih);
        $canonical = $this->hashService->canonicalize($enriched, $identity);
        $recomputed = $this->hashService->hash($enriched, $identity);
        $persisted = InvoiceHash::fromString((string) $existing->invoice_hash);

        if (! $recomputed->equals($persisted)) {
            throw new SecurityChainException(
                'Persisted invoice hash does not match the recomputed hash for this document.',
                documentIdentity: $identity,
                sequenceIdentity: 'egs:'.$existing->egs_unit_id,
                operation: 'idempotent_reuse',
                reason: 'hash_mismatch',
            );
        }

        return $this->artifact($existing, $canonical, $enriched, $reused);
    }

    private function artifact(
        EInvoiceSecurityRecord $record,
        CanonicalXml $canonical,
        string $enrichedXml,
        bool $reused,
    ): EInvoiceSecurityArtifact {
        return new EInvoiceSecurityArtifact(
            workspaceId: (int) $record->workspace_id,
            egsUnitId: (int) $record->egs_unit_id,
            eInvoiceDocumentId: (int) $record->e_invoice_document_id,
            icv: Icv::fromInt((int) $record->icv),
            pih: Pih::fromString((string) $record->pih),
            invoiceHash: InvoiceHash::fromString((string) $record->invoice_hash),
            canonicalXml: $canonical,
            enrichedXml: $enrichedXml,
            hashAlgorithm: (string) $record->hash_algorithm,
            canonicalizationMethod: (string) $record->canonicalization_method,
            sourceXmlDigest: (string) $record->source_xml_digest,
            reused: $reused,
        );
    }

    private function existingRecord(int $documentId): ?EInvoiceSecurityRecord
    {
        return EInvoiceSecurityRecord::withoutGlobalScopes()
            ->where('e_invoice_document_id', $documentId)
            ->first();
    }

    private function requirePersistedDocument(EInvoiceDocument $document): EInvoiceDocumentRecord
    {
        $record = EInvoiceDocumentRecord::withoutGlobalScopes()
            ->where('issued_document_snapshot_id', $document->sourceSnapshotId)
            ->first();

        if ($record === null) {
            throw new SecurityChainException(
                'Electronic document must be persisted before security generation.',
                documentIdentity: $this->documentIdentity($document),
                operation: 'load_document',
                reason: 'missing_document_record',
            );
        }

        if ((int) $record->workspace_id !== $document->workspaceId) {
            throw new SecurityChainException(
                'Persisted electronic document belongs to a different workspace.',
                documentIdentity: $this->documentIdentity($document),
                operation: 'load_document',
                reason: 'workspace_mismatch',
            );
        }

        return $record;
    }

    private function assertEligible(EInvoiceDocument $document): void
    {
        $kind = $document->kind();
        $allowed = [
            ElectronicDocumentKind::TaxInvoice,
            ElectronicDocumentKind::SimplifiedTaxInvoice,
            ElectronicDocumentKind::CreditNote,
            ElectronicDocumentKind::SimplifiedCreditNote,
            ElectronicDocumentKind::DebitNote,
            ElectronicDocumentKind::SimplifiedDebitNote,
        ];

        if (! in_array($kind, $allowed, true)) {
            throw new SecurityChainException(
                'Document kind is not eligible for the sales e-invoice security chain.',
                documentIdentity: $this->documentIdentity($document),
                operation: 'eligibility',
                reason: 'ineligible_kind',
            );
        }
    }

    private function assertXmlMatchesDocument(EInvoiceDocument $document, GeneratedEInvoiceXml $xml): void
    {
        if ($xml->workspaceId !== $document->workspaceId
            || $xml->sourceSnapshotId !== $document->sourceSnapshotId
            || $xml->sourceType !== $document->sourceType
            || $xml->sourceId !== $document->sourceId) {
            throw new SecurityChainException(
                'Generated XML identity does not match the electronic document.',
                documentIdentity: $this->documentIdentity($document),
                operation: 'bind_xml',
                reason: 'xml_identity_mismatch',
            );
        }
    }

    private function markDocumentGenerated(EInvoiceDocumentRecord $record): void
    {
        if ($record->compliance_status === ComplianceStatus::Ready) {
            $record->transitionCompliance(ComplianceStatus::Generated);
        }
    }

    private function documentIdentity(EInvoiceDocument $document): string
    {
        return $document->sourceType.':'.$document->sourceId.':snapshot:'.$document->sourceSnapshotId;
    }

    private function auditSuccess(EInvoiceSecurityArtifact $artifact, bool $reused): void
    {
        $this->audit->log(
            action: $reused ? 'e_invoice.security.reused' : 'e_invoice.security.generated',
            entityType: 'e_invoice_security_record',
            entityId: $artifact->eInvoiceDocumentId,
            newValues: [
                'e_invoice_document_id' => $artifact->eInvoiceDocumentId,
                'egs_unit_id' => $artifact->egsUnitId,
                'icv' => $artifact->icv->value(),
                'pih' => $artifact->pih->value(),
                'invoice_hash' => $artifact->invoiceHash->value(),
                'reused' => $reused,
            ],
            workspaceId: $artifact->workspaceId,
        );
    }

    private function auditFailure(EInvoiceDocument $document, EInvoiceSecurityException $exception): void
    {
        $this->audit->log(
            action: 'e_invoice.security.failed',
            entityType: 'e_invoice_document',
            entityId: $document->sourceSnapshotId,
            newValues: [
                'operation' => $exception->operation,
                'reason' => $exception->reason,
            ],
            workspaceId: $document->workspaceId,
        );
    }
}
