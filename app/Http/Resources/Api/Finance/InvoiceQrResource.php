<?php

namespace App\Http\Resources\Api\Finance;

use App\Services\Finance\Api\Dto\InvoiceQrDto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InvoiceQrDto */
class InvoiceQrResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var InvoiceQrDto $dto */
        $dto = $this->resource;

        return $dto->toArray();
    }
}
