<?php

namespace App\EInvoicing\Security;

use App\EInvoicing\QR\CryptographicQrFields;

/**
 * Immutable result of a local cryptographic stamp operation.
 * Not a ZATCA production identity unless status is production_signed
 * and a real CSID was used — Phase 9 never produces that state.
 */
final readonly class CryptographicStamp
{
    public function __construct(
        public StampStatus $status,
        public InvoiceHash $invoiceHash,
        public string $signatureDerBase64,
        public string $publicKeySpkiDer,
        public string $signatureAlgorithm,
        public string $curve,
        public CertificateFingerprint $certificateFingerprint,
        public ?int $certificateId,
        public string $signedInputIdentifier,
        public bool $productionIdentity,
    ) {}

    public function isProductionIdentity(): bool
    {
        return $this->productionIdentity && $this->status === StampStatus::ProductionSigned;
    }

    public function toQrFields(): CryptographicQrFields
    {
        return new CryptographicQrFields(
            ecdsaSignature: $this->signatureDerBase64,
            ecdsaPublicKey: $this->publicKeySpkiDer,
            zatcaCaSignature: null,
        );
    }
}
