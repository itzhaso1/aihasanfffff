<?php

namespace App\Http\Resources\Api\Finance;

use App\Services\Finance\Api\Dto\InvoiceSummaryDto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InvoiceSummaryDto */
class InvoiceSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var InvoiceSummaryDto $dto */
        $dto = $this->resource;

        return $dto->toArray();
    }
}
