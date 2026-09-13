<?php

namespace App\EInvoicing\Xml;

use RuntimeException;

class EInvoiceXmlValidationException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        string $message,
        public readonly array $errors = [],
        public readonly ?string $sourceIdentity = null,
    ) {
        $detail = $errors === [] ? $message : $message.': '.implode('; ', $errors);

        parent::__construct(
            $sourceIdentity !== null ? "source={$sourceIdentity} | {$detail}" : $detail
        );
    }
}
