<?php

namespace App\EInvoicing\QR\Harness;

use App\EInvoicing\QR\QrEncoder;
use App\EInvoicing\QR\QrField;
use App\EInvoicing\QR\QrFieldSet;
use App\EInvoicing\QR\QrPayload;
use App\EInvoicing\QR\QrPayloadProfile;
use App\EInvoicing\QR\QrTag;
use App\EInvoicing\Security\Harness\TestCryptographicArtifact;
use App\EInvoicing\Security\InvoiceHash;

/**
 * TEST ONLY assembly of cryptographic QR tags from artifacts.
 * Delegates TLV/Base64 to QrEncoder. Performs no ECDSA, hashing, or ICV allocation.
 */
final class TestQrCryptographicAssembler
{
    public function __construct(
        private readonly QrEncoder $encoder = new QrEncoder,
        private readonly TestQrTag6Encoder $tag6 = new TestQrTag6Encoder,
        private readonly TestQrTag7Encoder $tag7 = new TestQrTag7Encoder,
        private readonly TestQrTag8Encoder $tag8 = new TestQrTag8Encoder,
    ) {}

    /**
     * @param  list<QrField>  $phase8Tags1to5
     */
    public function assemble(
        array $phase8Tags1to5,
        InvoiceHash $invoiceHash,
        TestQrTag6Representation $tag6,
        TestCryptographicArtifact $artifact,
        TestQrTag8Representation $tag8,
        ?QrTag9Artifact $tag9 = null,
    ): QrPayload {
        foreach ($phase8Tags1to5 as $field) {
            if (in_array($field->tag->value(), [QrTag::INVOICE_HASH, QrTag::ECDSA_SIGNATURE, QrTag::ECDSA_PUBLIC_KEY, QrTag::ZATCA_CA_SIGNATURE], true)) {
                throw new \InvalidArgumentException('Phase 8 prefix must be Tags 1–5 only.');
            }
        }

        $fields = [
            ...$phase8Tags1to5,
            $this->tag6->field($invoiceHash, $tag6),
            $this->tag7->field($artifact->signature),
            $this->tag8->field($artifact->publicKey, $tag8),
        ];
        if ($tag9 !== null) {
            $fields[] = QrField::binary(QrTag::ZATCA_CA_SIGNATURE, $tag9->bytes);
        }

        return $this->encoder->encode(
            QrFieldSet::inOfficialOrder($fields),
            QrPayloadProfile::WithCryptographicFields,
        );
    }
}
