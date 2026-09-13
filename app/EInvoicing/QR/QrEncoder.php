<?php

namespace App\EInvoicing\QR;

/**
 * Official ZATCA QR TLV encoder.
 *
 * For each field: [1-byte tag][1-byte UTF-8 length][UTF-8 value bytes].
 * Fields are emitted in the order supplied. Callers must pass official tag order.
 * The payload is Base64 of the concatenated TLV bytes. No separators.
 */
final class QrEncoder
{
    /**
     * @param  list<QrField>  $fields
     */
    public function encode(array $fields, QrPayloadProfile $profile = QrPayloadProfile::Phase8Unsigned): QrPayload
    {
        if ($fields === []) {
            throw new QrEncodingException('QR payload requires at least one field.', reason: 'empty_fields');
        }

        $tlv = '';
        foreach ($fields as $field) {
            $tlv .= chr($field->tag->value());
            $tlv .= chr($field->byteLength());
            $tlv .= $field->utf8Bytes();
        }

        return new QrPayload(
            fields: $fields,
            tlvBytes: $tlv,
            base64: base64_encode($tlv),
            profile: $profile,
        );
    }
}
