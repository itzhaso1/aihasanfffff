<?php

namespace App\Services\Merchant;

use App\Models\MerchantProfile;
use App\Models\Workspace;
use App\Services\Feature\FeatureAccessService;
use RuntimeException;

class MerchantPaymentEligibilityService
{
    public function __construct(
        private readonly FeatureAccessService $featureAccessService,
        private readonly MerchantVerificationService $verificationService,
    ) {}

    public function canAcceptCustomerPayments(Workspace $workspace): bool
    {
        $snapshot = $this->statusSnapshot($workspace);

        return (bool) $snapshot['eligible'];
    }

    /**
     * @return array{
     *     plan_feature: bool,
     *     verification: string,
     *     provider: string,
     *     eligible: bool,
     *     blockers: array<int, string>
     * }
     */
    public function statusSnapshot(Workspace $workspace): array
    {
        $profile = $this->verificationService->profile($workspace);

        $hasPlanFeature = $this->featureAccessService->workspaceHasFeature($workspace, 'payments')
            || $this->featureAccessService->workspaceHasFeature($workspace, 'payment_gateway');

        $verification = (string) $profile->verification_status;
        $provider = (string) $profile->provider_onboarding_status;

        $blockers = [];

        if (! $hasPlanFeature) {
            $blockers[] = 'باقتك الحالية لا تتضمن ميزة استقبال مدفوعات العملاء.';
        }

        if ($verification !== MerchantProfile::VERIFICATION_APPROVED) {
            $blockers[] = match ($verification) {
                MerchantProfile::VERIFICATION_NOT_REQUESTED => 'لم يتم طلب توثيق التاجر بعد.',
                MerchantProfile::VERIFICATION_DOCUMENTS_REQUIRED => 'يلزم رفع المستندات المطلوبة للتوثيق.',
                MerchantProfile::VERIFICATION_PENDING_REVIEW => 'طلب التوثيق قيد المراجعة من المنصة.',
                MerchantProfile::VERIFICATION_REJECTED => 'تم رفض التوثيق. راجع السبب وأعد الإرسال.',
                MerchantProfile::VERIFICATION_SUSPENDED => 'تم تعليق حساب التاجر.',
                default => 'حالة التوثيق لا تسمح باستقبال المدفوعات.',
            };
        }

        if ($provider !== MerchantProfile::PROVIDER_ACTIVE) {
            $blockers[] = match ($provider) {
                MerchantProfile::PROVIDER_NOT_STARTED => 'لم يبدأ تفعيل بوابة التسوية للتاجر بعد.',
                MerchantProfile::PROVIDER_PENDING => 'تفعيل بوابة التسوية للتاجر قيد الانتظار (HyperPay marketplace غير مكتمل الإعداد).',
                MerchantProfile::PROVIDER_FAILED => 'فشل تفعيل بوابة التسوية للتاجر.',
                default => 'بوابة التسوية غير نشطة.',
            };
        }

        return [
            'plan_feature' => $hasPlanFeature,
            'verification' => $verification,
            'provider' => $provider,
            'eligible' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    public function assertCanAcceptCustomerPayments(Workspace $workspace): void
    {
        $snapshot = $this->statusSnapshot($workspace);
        if ($snapshot['eligible']) {
            return;
        }

        $message = implode(' ', $snapshot['blockers']);
        throw new RuntimeException($message !== '' ? $message : 'لا يمكن استقبال مدفوعات العملاء حالياً.');
    }
}
