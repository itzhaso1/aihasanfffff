<?php

namespace App\Http\Resources\Mobile;

use App\Services\Commerce\Intelligence\CommerceRecommendationEvaluation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CommerceRecommendationEvaluation */
class CommerceRecommendationEvaluationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
