<?php

namespace App\EInvoicing\QR;

/**
 * Assembles QR fields in official tag order 1–9. Input order is ignored.
 */
final class QrFieldSet
{
    /**
     * @param  list<QrField>  $fields
     * @return list<QrField>
     */
    public static function inOfficialOrder(array $fields): array
    {
        $byTag = [];
        foreach ($fields as $field) {
            $tag = $field->tag->value();
            if (isset($byTag[$tag])) {
                throw new QrEncodingException(
                    'Duplicate QR tag.',
                    field: 'tag_'.$tag,
                    reason: 'duplicate_tag',
                );
            }
            $byTag[$tag] = $field;
        }

        $ordered = [];
        foreach (QrTag::OFFICIAL_ORDER as $tag) {
            if (isset($byTag[$tag])) {
                $ordered[] = $byTag[$tag];
            }
        }

        return $ordered;
    }
}
