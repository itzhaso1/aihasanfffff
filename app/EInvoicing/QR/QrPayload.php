<?php

namespace App\EInvoicing\QR;

/**
 * Immutable official-order TLV payload and its Base64 representation.
 */
final readonly class QrPayload
{
    /**
     * @param  list<QrField>  $fields
     */
    public function __construct(
        public array $fields,
        public string $tlvBytes,
        public string $base64,
        public QrPayloadProfile $profile,
    ) {}

    public function hasTag(int $tag): bool
    {
        foreach ($this->fields as $field) {
            if ($field->tag->value() === $tag) {
                return true;
            }
        }

        return false;
    }

    public function valueForTag(int $tag): ?string
    {
        foreach ($this->fields as $field) {
            if ($field->tag->value() === $tag) {
                return $field->value;
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    public function tagOrder(): array
    {
        return array_map(static fn (QrField $field): int => $field->tag->value(), $this->fields);
    }

    public function includesCryptographicTags(): bool
    {
        return $this->hasTag(QrTag::ECDSA_SIGNATURE)
            || $this->hasTag(QrTag::ECDSA_PUBLIC_KEY)
            || $this->hasTag(QrTag::ZATCA_CA_SIGNATURE);
    }
}
