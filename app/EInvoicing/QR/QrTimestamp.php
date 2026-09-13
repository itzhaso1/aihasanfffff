<?php

namespace App\EInvoicing\QR;

use App\EInvoicing\EInvoiceDocument;

/**
 * Serializes the issued-document timestamp for QR Tag 3.
 *
 * Official forms (ZATCA QR / XML IssueDate + IssueTime):
 * - YYYY-MM-DDTHH:MM:SS for local (KSA) time
 * - YYYY-MM-DDTHH:MM:SSZ for UTC
 *
 * This is not now(), QR generation time, or a second clock.
 */
final class QrTimestamp
{
    public static function fromDocument(EInvoiceDocument $document): string
    {
        $issuedAt = $document->issuedAt;
        if ($issuedAt === null || trim($issuedAt) === '') {
            throw new QrEncodingException(
                'QR Tag 3 requires the issued-document timestamp. It will not use the current time.',
                documentIdentity: self::identity($document),
                field: 'tag_3',
                reason: 'missing_issued_at',
            );
        }

        if (preg_match('/(\d{4}-\d{2}-\d{2})[T\s](\d{2}:\d{2}:\d{2})(Z|[+-]\d{2}:\d{2})?/', $issuedAt, $matches) !== 1) {
            throw new QrEncodingException(
                'Issued-document timestamp is not a complete date and time for QR Tag 3.',
                documentIdentity: self::identity($document),
                field: 'tag_3',
                reason: 'unparseable_issued_at',
            );
        }

        $date = $matches[1];
        $time = $matches[2];
        $zone = $matches[3] ?? '';

        if ($document->issueDate !== null && $document->issueDate !== '') {
            $issueDate = substr($document->issueDate, 0, 10);
            if ($issueDate !== $date) {
                throw new QrEncodingException(
                    'Issued-at date does not match the issued-document issue date.',
                    documentIdentity: self::identity($document),
                    field: 'tag_3',
                    reason: 'issue_date_mismatch',
                );
            }
        }

        if ($zone === 'Z' || $zone === '+00:00') {
            return $date.'T'.$time.'Z';
        }

        return $date.'T'.$time;
    }

    private static function identity(EInvoiceDocument $document): string
    {
        return $document->sourceType.':'.$document->sourceId.':snapshot:'.$document->sourceSnapshotId;
    }
}
