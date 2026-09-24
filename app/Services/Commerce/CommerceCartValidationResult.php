<?php

namespace App\Services\Commerce;

final readonly class CommerceCartValidationResult
{
    /**
     * @param  list<CommerceValidatedCartLine>  $lines
     * @param  list<array{cart_item_id:int|null,code:string,message:string}>  $problems
     */
    public function __construct(
        public array $lines,
        public array $problems,
    ) {}

    public function isValid(): bool
    {
        return $this->problems === [];
    }
}
