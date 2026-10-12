<?php

namespace App\Services\Payment\Contracts;

use App\Models\MerchantProfile;
use App\Models\Workspace;

interface MerchantSettlementProviderInterface
{
    /**
     * @return array{status: string, provider_merchant_id?: string|null, message: string}
     */
    public function startOnboarding(Workspace $workspace, MerchantProfile $profile): array;

    /**
     * @return array{status: string, provider_merchant_id?: string|null, message: string}
     */
    public function syncOnboardingStatus(MerchantProfile $profile): array;
}
