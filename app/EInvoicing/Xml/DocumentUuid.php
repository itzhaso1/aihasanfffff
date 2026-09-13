<?php

namespace App\EInvoicing\Xml;

use App\EInvoicing\EInvoiceDocument;
use Symfony\Component\Uid\Uuid;

/**
 * Deterministic XML document UUID (KSA-1 / cbc:UUID).
 *
 * This is an RFC 4122 UUID v5 derived from the immutable snapshot identity.
 * It is not ICV, PIH, or a cryptographic invoice hash.
 */
final class DocumentUuid
{
    public static function fromDocument(EInvoiceDocument $document): string
    {
        $name = implode(':', [
            'hasem',
            'e-invoice-document',
            (string) $document->workspaceId,
            $document->sourceType,
            (string) $document->sourceId,
            (string) $document->sourceSnapshotId,
            $document->documentNumber,
        ]);

        return (string) Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_URL), $name);
    }
}
