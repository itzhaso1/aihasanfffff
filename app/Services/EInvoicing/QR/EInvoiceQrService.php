<?php

namespace App\Services\EInvoicing\QR;

use App\EInvoicing\EInvoiceDocument;
use App\EInvoicing\QR\CryptographicQrFields;
use App\EInvoicing\QR\CryptographicStampQrMapper;
use App\EInvoicing\QR\QrEncoder;
use App\EInvoicing\QR\QrEncodingException;
use App\EInvoicing\QR\QrField;
use App\EInvoicing\QR\QrFieldSet;
use App\EInvoicing\QR\QrPayload;
use App\EInvoicing\QR\QrPayloadProfile;
use App\EInvoicing\QR\QrTag;
use App\EInvoicing\QR\QrTimestamp;
use App\EInvoicing\Security\CryptographicStamp;
use App\EInvoicing\Security\InvoiceHash;
use App\Models\EInvoicing\EInvoiceSecurityRecord;

/**
 * Snapshot + Phase 7 invoice hash → official TLV QR payload (Tags 1–6).
 *
 * Does not recalculate tax, re-hash XML, sign, render images, or call ZATCA.
 * Tags 7–9 are included only when a caller supplies cryptographic artifacts.
 */
final class EInvoiceQrService
{
    public function __construct(private readonly QrEncoder $encoder = new QrEncoder) {}

    public function generate(
        EInvoiceDocument $document,
        InvoiceHash $invoiceHash,
        ?CryptographicQrFields $cryptographicFields = null,
    ): QrPayload {
        $cryptographicFields ??= CryptographicQrFields::none();
        $fields = [
            new QrField(QrTag::fromInt(QrTag::SELLER_NAME), $this->requiredText(
                $document->seller->name,
                $document,
                'tag_1',
                'Seller name is missing from the issued-document snapshot.',
            )),
            new QrField(QrTag::fromInt(QrTag::SELLER_VAT), $this->requiredText(
                $document->seller->vatNumber,
                $document,
                'tag_2',
                'Seller VAT number is missing from the issued-document snapshot.',
            )),
            new QrField(QrTag::fromInt(QrTag::TIMESTAMP), QrTimestamp::fromDocument($document)),
            new QrField(QrTag::fromInt(QrTag::TOTAL_WITH_VAT), $this->requiredMoney(
                $document->totals->total,
                $document,
                'tag_4',
                'Invoice total including VAT is missing from the issued-document snapshot.',
            )),
            new QrField(QrTag::fromInt(QrTag::VAT_TOTAL), $this->requiredMoney(
                $document->totals->taxAmount,
                $document,
                'tag_5',
                'VAT total is missing from the issued-document snapshot.',
            )),
            new QrField(QrTag::fromInt(QrTag::INVOICE_HASH), $invoiceHash->value()),
        ];

        if ($cryptographicFields->ecdsaSignature !== null) {
            $fields[] = QrField::of(QrTag::ECDSA_SIGNATURE, $cryptographicFields->ecdsaSignature);
        }
        if ($cryptographicFields->ecdsaPublicKey !== null) {
            $fields[] = mb_check_encoding($cryptographicFields->ecdsaPublicKey, 'UTF-8')
                ? QrField::of(QrTag::ECDSA_PUBLIC_KEY, $cryptographicFields->ecdsaPublicKey)
                : QrField::binary(QrTag::ECDSA_PUBLIC_KEY, $cryptographicFields->ecdsaPublicKey);
        }
        if ($cryptographicFields->zatcaCaSignature !== null) {
            $fields[] = mb_check_encoding($cryptographicFields->zatcaCaSignature, 'UTF-8')
                ? QrField::of(QrTag::ZATCA_CA_SIGNATURE, $cryptographicFields->zatcaCaSignature)
                : QrField::binary(QrTag::ZATCA_CA_SIGNATURE, $cryptographicFields->zatcaCaSignature);
        }

        $profile = $cryptographicFields->hasAny()
            ? QrPayloadProfile::WithCryptographicFields
            : QrPayloadProfile::Phase8Unsigned;

        return $this->encoder->encode(QrFieldSet::inOfficialOrder($fields), $profile);
    }

    public function generateFromSecurityRecord(
        EInvoiceDocument $document,
        EInvoiceSecurityRecord $security,
    ): QrPayload {
        if ((int) $security->workspace_id !== $document->workspaceId) {
            throw new QrEncodingException(
                'Security record workspace does not match the electronic document.',
                documentIdentity: $this->identity($document),
                reason: 'workspace_mismatch',
            );
        }

        return $this->generate(
            $document,
            InvoiceHash::fromString((string) $security->invoice_hash),
            CryptographicQrFields::none(),
        );
    }

    public function generateWithStamp(
        EInvoiceDocument $document,
        EInvoiceSecurityRecord $security,
        CryptographicStamp $stamp,
        ?string $externallyProvisionedZatcaCaSignature = null,
    ): QrPayload {
        if (! $stamp->invoiceHash->equals(InvoiceHash::fromString((string) $security->invoice_hash))) {
            throw new QrEncodingException(
                'Cryptographic stamp hash does not match the Phase 7 security record.',
                documentIdentity: $this->identity($document),
                reason: 'hash_mismatch',
            );
        }

        return $this->generate(
            $document,
            InvoiceHash::fromString((string) $security->invoice_hash),
            (new CryptographicStampQrMapper)->fields($stamp, $externallyProvisionedZatcaCaSignature),
        );
    }

    private function requiredText(?string $value, EInvoiceDocument $document, string $field, string $message): string
    {
        if ($value === null || trim($value) === '') {
            throw new QrEncodingException(
                $message,
                documentIdentity: $this->identity($document),
                field: $field,
                reason: 'missing_required_field',
            );
        }

        return $value;
    }

    private function requiredMoney(string $value, EInvoiceDocument $document, string $field, string $message): string
    {
        if (trim($value) === '') {
            throw new QrEncodingException(
                $message,
                documentIdentity: $this->identity($document),
                field: $field,
                reason: 'missing_required_field',
            );
        }

        return $value;
    }

    private function identity(EInvoiceDocument $document): string
    {
        return $document->sourceType.':'.$document->sourceId.':snapshot:'.$document->sourceSnapshotId;
    }
}
