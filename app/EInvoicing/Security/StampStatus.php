<?php

namespace App\EInvoicing\Security;

/**
 * Explicit stamp states. Having a signer object is not a ZATCA identity.
 */
enum StampStatus: string
{
    case Unsigned = 'unsigned';

    case TestSigned = 'test_signed';

    case ProductionSigned = 'production_signed';
}
