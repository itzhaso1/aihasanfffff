<?php

namespace App\Enums\EInvoicing;

enum PrivateKeyStoreDriver: string
{
    /** In-memory / fixture-backed driver for local and automated tests only. */
    case DevMemory = 'dev_memory';
}
