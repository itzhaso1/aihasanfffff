<?php

namespace App\Http\Resources\Mobile;

use App\Models\WorkspaceCompanyProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkspaceCompanyProfile */
class CompanyProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'company_name' => $this->company_name,
            'about' => $this->about,
            'offerings' => $this->offerings,
            'services' => $this->services,
            'audience' => $this->audience,
            'reply_guidelines' => $this->reply_guidelines,
        ];
    }
}
