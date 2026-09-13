<?php

namespace App\EInvoicing\QR\Harness;

/**
 * TEST ONLY Tag 8 representation candidates. Not a production QR encoding.
 */
enum TestQrTag8Representation: string
{
    case SpkiDer = 'test.tag8.spki_der';

    case UncompressedPoint = 'test.tag8.uncompressed_point';
}
