<?php

namespace App\EInvoicing\QR;

/**
 * Official ZATCA QR tags (Security Features Implementation Standards, 19 May 2023).
 * Only tags 1–9 are defined. This type does not invent additional tags.
 */
final readonly class QrTag
{
    public const SELLER_NAME = 1;

    public const SELLER_VAT = 2;

    public const TIMESTAMP = 3;

    public const TOTAL_WITH_VAT = 4;

    public const VAT_TOTAL = 5;

    public const INVOICE_HASH = 6;

    public const ECDSA_SIGNATURE = 7;

    public const ECDSA_PUBLIC_KEY = 8;

    public const ZATCA_CA_SIGNATURE = 9;

    /**
     * Official encoding order. Not alphabetical.
     *
     * @var list<int>
     */
    public const OFFICIAL_ORDER = [1, 2, 3, 4, 5, 6, 7, 8, 9];

    private function __construct(private int $value) {}

    public static function fromInt(int $value): self
    {
        if ($value < 1 || $value > 9) {
            throw new QrEncodingException(
                'QR tag must be an official ZATCA tag 1–9.',
                field: 'tag',
                reason: 'unsupported_tag',
            );
        }

        return new self($value);
    }

    public function value(): int
    {
        return $this->value;
    }
}
