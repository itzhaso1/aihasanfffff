<?php

namespace App\Exceptions;

use RuntimeException;

final class CommerceCartValidationException extends RuntimeException
{
    /**
     * @param  list<array{cart_item_id:int|null,code:string,message:string}>  $problems
     */
    public function __construct(
        public readonly array $problems,
        string $message = 'The commerce cart is no longer valid.',
    ) {
        parent::__construct($message);
    }
}
