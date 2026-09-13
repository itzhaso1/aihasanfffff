@extends('platform.layout')

@section('content')
<div dir="rtl" class="space-y-6">
    @include('platform.partials.nav')
    @include('partials.flash')

    <div class="flex items-center justify-between gap-3">
        <h1 class="text-xl font-bold text-gray-900">تفاصيل توثيق التاجر</h1>
        <a href="{{ route('platform.merchant-verifications.index') }}" class="text-sm text-blue-600">العودة للطابور</a>
    </div>

    <div class="rounded-xl border bg-white p-5 space-y-2 text-sm">
        <p><span class="font-semibold">مساحة العمل:</span> {{ $profile->workspace?->name }}</p>
        <p><span class="font-semibold">المالك:</span> {{ $profile->workspace?->owner?->email }}</p>
        <p><span class="font-semibold">حالة التوثيق:</span> {{ \App\Support\MerchantStatusLabels::verificationLabel($profile->verification_status) }}</p>
        <p><span class="font-semibold">حالة البوابة:</span> {{ \App\Support\MerchantStatusLabels::providerLabel($profile->provider_onboarding_status) }}</p>
        <p><span class="font-semibold">معرّف المزوّد:</span> {{ $profile->provider_merchant_id ?: '—' }}</p>
        @if($profile->rejection_reason)
            <p class="text-red-700"><span class="font-semibold">سبب الرفض:</span> {{ $profile->rejection_reason }}</p>
        @endif
        @if(!empty($profile->metadata['provider_onboarding_message']))
            <p class="text-amber-800"><span class="font-semibold">ملاحظة البوابة:</span> {{ $profile->metadata['provider_onboarding_message'] }}</p>
        @endif
    </div>

    <div class="rounded-xl border bg-white p-5">
        <h2 class="mb-3 font-semibold">المستندات</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-3 py-2 text-right">النوع</th>
                        <th class="px-3 py-2 text-right">الملف</th>
                        <th class="px-3 py-2 text-right">الانتهاء</th>
                        <th class="px-3 py-2 text-right">الحالة</th>
                        <th class="px-3 py-2 text-right">عرض</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($documents as $document)
                        <tr>
                            <td class="px-3 py-2">{{ $document->document_type_code }}</td>
                            <td class="px-3 py-2">{{ $document->original_name }}</td>
                            <td class="px-3 py-2">{{ $document->expires_at?->format('Y-m-d') ?? '—' }}</td>
                            <td class="px-3 py-2">{{ \App\Support\MerchantStatusLabels::documentLabel($document->status) }}</td>
                            <td class="px-3 py-2">
                                <a class="text-blue-600" href="{{ route('platform.merchant-verifications.documents.download', $document->id) }}">تحميل آمن</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-4 text-center text-gray-500">لا مستندات</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        <form method="POST" action="{{ route('platform.merchant-verifications.approve', $profile->id) }}" class="rounded-xl border bg-white p-4 space-y-2">
            @csrf
            <h3 class="font-semibold text-green-700">موافقة</h3>
            <textarea name="notes" rows="2" class="w-full rounded-lg border-gray-300" placeholder="ملاحظات اختيارية"></textarea>
            <button class="rounded-lg bg-green-600 px-4 py-2 text-sm text-white">اعتماد التوثيق</button>
        </form>

        <form method="POST" action="{{ route('platform.merchant-verifications.reject', $profile->id) }}" class="rounded-xl border bg-white p-4 space-y-2">
            @csrf
            <h3 class="font-semibold text-red-700">رفض</h3>
            <textarea name="reason" required rows="2" class="w-full rounded-lg border-gray-300" placeholder="سبب الرفض"></textarea>
            <button class="rounded-lg bg-red-600 px-4 py-2 text-sm text-white">رفض</button>
        </form>

        <form method="POST" action="{{ route('platform.merchant-verifications.request-documents', $profile->id) }}" class="rounded-xl border bg-white p-4 space-y-2">
            @csrf
            <h3 class="font-semibold text-amber-700">طلب مستندات</h3>
            <textarea name="reason" required rows="2" class="w-full rounded-lg border-gray-300" placeholder="ما المطلوب؟"></textarea>
            <button class="rounded-lg bg-amber-600 px-4 py-2 text-sm text-white">طلب مستندات</button>
        </form>

        <form method="POST" action="{{ route('platform.merchant-verifications.suspend', $profile->id) }}" class="rounded-xl border bg-white p-4 space-y-2">
            @csrf
            <h3 class="font-semibold text-slate-700">تعليق</h3>
            <textarea name="reason" required rows="2" class="w-full rounded-lg border-gray-300" placeholder="سبب التعليق"></textarea>
            <button class="rounded-lg bg-slate-700 px-4 py-2 text-sm text-white">تعليق الحساب</button>
        </form>
    </div>
</div>
@endsection
