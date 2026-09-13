<?php

namespace App\EInvoicing\QR\Harness;

/**
 * Future Tag 9 boundary. Production must not generate this locally.
 */
interface QrTag9Provider
{
    public function artifact(): QrTag9Artifact;
}
