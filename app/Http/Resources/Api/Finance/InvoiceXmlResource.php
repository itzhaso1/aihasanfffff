<?php

namespace App\Http\Resources\Api\Finance;

use App\Services\Finance\Api\Dto\InvoiceXmlDto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InvoiceXmlDto */
class InvoiceXmlResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var InvoiceXmlDto $dto */
        $dto = $this->resource;

        return $dto->toArray();
    }
}
