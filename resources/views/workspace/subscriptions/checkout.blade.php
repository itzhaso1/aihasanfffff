<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold text-gray-900">{{ __('إتمام الدفع للاشتراك') }}</h2>
    </x-slot>

    <div class="mx-auto max-w-4xl space-y-6" dir="rtl">
        @include('workspace.partials.nav')
        @include('partials.flash')

        <section class="rounded-2xl border border-[#BDEFE5] bg-[#F3FCFA] p-5">
            <h3 class="text-base font-semibold text-[#067e6b]">{{ __('عملية الدفع') }} #{{ $checkoutSession->id }}</h3>
            <p class="mt-2 text-sm text-gray-700">
                {{ __('هذه الصفحة تنتظر تأكيد الدفع من بوابة الدفع. لن يتم تفعيل الاشتراك من تأكيد الواجهة.') }}
            </p>
        </section>

        <section class="rounded-2xl border bg-white p-6">
            <h3 class="mb-4 font-semibold">{{ __('تفاصيل الباقة المختارة') }}</h3>
            <div class="grid gap-3 text-sm text-gray-700 sm:grid-cols-2">
                <p><span class="font-semibold">{{ __('اسم الباقة') }}:</span> {{ $checkoutSession->plan?->display_name_ar ?: $checkoutSession->plan?->name }}</p>
                <p><span class="font-semibold">{{ __('سعر الباقة') }}:</span> {{ number_format((float) $checkoutSession->amount, 2) }} {{ $checkoutSession->currency }}</p>
                <p><span class="font-semibold">{{ __('الفترة') }}:</span> {{ $periodLabel }}</p>
                <p><span class="font-semibold">{{ __('ينتهي') }}:</span> {{ $checkoutSession->expires_at?->format('Y-m-d H:i') ?? '—' }}</p>
                <p><span class="font-semibold">{{ __('رقم المرجع') }}:</span> {{ $checkoutSession->provider_checkout_id }}</p>
                <p><span class="font-semibold">{{ __('بوابة الدفع') }}:</span> {{ $checkoutSession->payment_provider }}</p>
            </div>
        </section>

        <section class="rounded-2xl border bg-white p-6">
            <h3 class="mb-4 font-semibold">{{ __('حالة العملية') }}</h3>
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl border border-gray-200 p-4 text-sm">
                    <p class="text-xs text-gray-500">{{ __('حالة الدفع') }}</p>
                    <p class="mt-1 font-semibold text-gray-900">{{ $checkoutSession->checkout_status }}</p>
                </div>
                <div class="rounded-xl border border-gray-200 p-4 text-sm">
                    <p class="text-xs text-gray-500">{{ __('الدفع') }}</p>
                    <p class="mt-1 font-semibold text-gray-900">{{ $checkoutSession->payment_status }}</p>
                </div>
                <div class="rounded-xl border border-gray-200 p-4 text-sm">
                    <p class="text-xs text-gray-500">{{ __('الاشتراك') }}</p>
                    <p class="mt-1 font-semibold text-gray-900">{{ $checkoutSession->subscription_status }}</p>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border bg-white p-6">
            @if($isVerifiedPaid)
                <div class="rounded-xl border border-green-200 bg-green-50 p-4 text-sm text-green-800" role="status">
                    {{ __('تم الدفع بالفعل وتفعيل الاشتراك بنجاح.') }}
                </div>
                <a href="{{ route('workspace.subscriptions.index') }}" class="mt-4 inline-flex rounded-lg bg-[#06C2A4] px-4 py-2 text-sm font-semibold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-[#06C2A4] focus-visible:ring-offset-2">
                    {{ __('العودة إلى الاشتراكات') }}
                </a>
            @elseif($isExpired)
                <div class="rounded-xl border border-gray-300 bg-gray-50 p-4 text-sm text-gray-800" role="alert">
                    {{ __('انتهت صلاحية عملية الدفع. ابدأ عملية جديدة من السيرفر.') }}
                </div>
                @if($canManageBilling && $checkoutSession->plan_id)
                    <form method="POST" action="{{ route('workspace.subscriptions.store') }}" class="mt-4">
                        @csrf
                        <input type="hidden" name="plan_id" value="{{ $checkoutSession->plan_id }}">
                        <button type="submit" class="inline-flex rounded-lg bg-[#06C2A4] px-4 py-2 text-sm font-semibold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-[#06C2A4] focus-visible:ring-offset-2">
                            {{ __('بدء عملية دفع جديدة') }}
                        </button>
                    </form>
                @endif
            @else
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" role="status">
                    {{ __('العملية بانتظار تحقق الدفع من بوابة الدفع. لا يمكن تفعيل الاشتراك من الواجهة.') }}
                </div>
                <a href="{{ route('workspace.subscriptions.index') }}" class="mt-4 inline-flex rounded-lg bg-[#06C2A4] px-4 py-2 text-sm font-semibold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-[#06C2A4] focus-visible:ring-offset-2">
                    {{ __('العودة إلى الاشتراكات') }}
                </a>
            @endif
        </section>
    </div>
</x-app-layout>
