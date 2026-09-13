<?php

namespace App\Services\Payment\Providers;

use App\Models\MerchantProfile;
use App\Models\Workspace;
use App\Services\Payment\Contracts\MerchantSettlementProviderInterface;

/**
 * HyperPay merchant marketplace / split settlement onboarding.
 *
 * Honesty notes:
 * - Real HyperPay marketplace onboarding APIs are NOT wired here.
 * - Without credentials + merchant_onboarding_enabled, status stays pending.
 * - provider_onboarding_status=active is only set in local_sandbox mode when
 *   HYPERPAY_MERCHANT_SANDBOX_AUTO_APPROVE=true (staging-only convenience).
 */
class HyperPayMerchantSettlementProvider implements MerchantSettlementProviderInterface
{
    public function startOnboarding(Workspace $workspace, MerchantProfile $profile): array
    {
        if ($this->sandboxAutoApproveEnabled()) {
            return [
                'status' => MerchantProfile::PROVIDER_ACTIVE,
                'provider_merchant_id' => $profile->provider_merchant_id
                    ?: 'sandbox_merchant_'.$workspace->id,
                'message' => 'تم تفعيل التاجر تلقائياً في وضع sandbox المحلي (للتجربة فقط — ليس تكاملاً حقيقياً مع HyperPay).',
            ];
        }

        if (! $this->onboardingConfigured()) {
            return [
                'status' => MerchantProfile::PROVIDER_PENDING,
                'provider_merchant_id' => $profile->provider_merchant_id,
                'message' => 'توثيق المنصة مكتمل، لكن تفعيل HyperPay marketplace/split onboarding غير مُعد بعد. الحساب يبقى معلقاً حتى ضبط بيانات الاعتماد.',
            ];
        }

        // Credentials exist but no real marketplace API integration yet — stay pending.
        return [
            'status' => MerchantProfile::PROVIDER_PENDING,
            'provider_merchant_id' => $profile->provider_merchant_id,
            'message' => 'بيانات اعتماد HyperPay موجودة، لكن واجهة marketplace/split onboarding غير مفعّلة بالكامل بعد. الحالة: قيد الانتظار.',
        ];
    }

    public function syncOnboardingStatus(MerchantProfile $profile): array
    {
        if ($profile->provider_onboarding_status === MerchantProfile::PROVIDER_ACTIVE) {
            return [
                'status' => MerchantProfile::PROVIDER_ACTIVE,
                'provider_merchant_id' => $profile->provider_merchant_id,
                'message' => 'التاجر مفعّل حالياً.',
            ];
        }

        if ($this->sandboxAutoApproveEnabled()) {
            return [
                'status' => MerchantProfile::PROVIDER_ACTIVE,
                'provider_merchant_id' => $profile->provider_merchant_id
                    ?: 'sandbox_merchant_'.$profile->workspace_id,
                'message' => 'مزامنة sandbox: تفعيل تلقائي (staging فقط).',
            ];
        }

        return [
            'status' => MerchantProfile::PROVIDER_PENDING,
            'provider_merchant_id' => $profile->provider_merchant_id,
            'message' => 'لا توجد واجهة مزامنة حقيقية مع HyperPay marketplace بعد. الحالة تبقى قيد الانتظار.',
        ];
    }

    private function onboardingConfigured(): bool
    {
        if (! (bool) config('services.hyperpay.merchant_onboarding_enabled', false)) {
            return false;
        }

        $entityId = (string) config('services.hyperpay.entity_id', '');
        $accessToken = (string) config('services.hyperpay.access_token', '');

        return $entityId !== '' && $accessToken !== '';
    }

    /**
     * Staging-only local_sandbox mode.
     * Enabled only when HYPERPAY_MERCHANT_SANDBOX_AUTO_APPROVE=true
     * (mapped via config services.hyperpay.merchant_sandbox_auto_approve).
     * Never invents a successful HyperPay API call in production.
     */
    private function sandboxAutoApproveEnabled(): bool
    {
        return (bool) config('services.hyperpay.merchant_sandbox_auto_approve', false);
    }
}
