<?php

namespace App\Http\Resources\Api\Finance;

use App\Services\Finance\Api\Dto\InvoiceDetailsDto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InvoiceDetailsDto */
class InvoiceDetailsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var InvoiceDetailsDto $dto */
        $dto = $this->resource;

        return $dto->toArray();
    }
}
