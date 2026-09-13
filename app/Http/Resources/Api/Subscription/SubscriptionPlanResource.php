<?php

namespace App\Http\Resources\Api\Subscription;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Plan */
class SubscriptionPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'display_name_ar' => $this->display_name_ar,
            'description' => $this->description,
            'tier' => $this->tier,
            'price' => $this->price,
            'currency' => $this->currency,
            'billing_period' => $this->billing_period,
            'trial_days' => $this->trial_days,
            'features' => is_array($this->features) ? array_values($this->features) : [],
            'limits' => is_array($this->limits) ? $this->limits : [],
        ];
    }
}
