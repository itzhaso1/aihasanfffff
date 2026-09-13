<?php

namespace App\EInvoicing\QR\Harness;

/**
 * TEST ONLY Tag 6 representation candidates. Not a production Phase 8 change.
 */
enum TestQrTag6Representation: string
{
    case RawSha256 = 'test.tag6.raw_sha256';

    case Base64Text = 'test.tag6.base64_text';
}
