<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold text-gray-900">مدفوعات التاجر والتوثيق</h2>
    </x-slot>

    @php
        $verificationLabel = \App\Support\MerchantStatusLabels::verificationLabel($profile->verification_status);
        $providerLabel = \App\Support\MerchantStatusLabels::providerLabel($profile->provider_onboarding_status);
    @endphp

    <div class="mx-auto max-w-5xl space-y-6 py-8 px-4" dir="rtl">
        @include('workspace.partials.nav')
        @include('partials.flash')

        <div class="rounded-2xl border border-[#BDEFE5] bg-[#F3FCFA] p-5">
            <h3 class="text-base font-semibold text-[#067e6b]">حالة الأهلية لاستقبال المدفوعات</h3>
            <p class="mt-2 text-sm text-gray-700">
                @if($eligibility['eligible'])
                    يمكنك استقبال مدفوعات العملاء.
                @else
                    غير مؤهل حالياً لاستقبال أموال العملاء.
                @endif
            </p>
            @if(!empty($eligibility['blockers']))
                <ul class="mt-3 list-disc pr-5 text-sm text-amber-900 space-y-1">
                    @foreach($eligibility['blockers'] as $blocker)
                        <li>{{ $blocker }}</li>
                    @endforeach
                </ul>
            @endif
            <div class="mt-4 grid gap-2 text-sm text-gray-700 sm:grid-cols-3">
                <p><span class="font-semibold">ميزة الباقة:</span> {{ $eligibility['plan_feature'] ? 'متاحة' : 'غير متاحة' }}</p>
                <p><span class="font-semibold">التوثيق:</span> {{ $verificationLabel }}</p>
                <p><span class="font-semibold">بوابة التسوية:</span> {{ $providerLabel }}</p>
            </div>
            <p class="mt-3 text-xs text-gray-500">
                اشتراك الباقة منفصل عن أهلية استقبال أموال العملاء. موافقة التوثيق + تفعيل المزوّد مطلوبان معاً.
            </p>
        </div>

        <div class="rounded-2xl border bg-white p-5 space-y-4">
            <h3 class="font-semibold text-gray-900">تفعيل استقبال المدفوعات</h3>
            @if($profile->verification_status === 'not_requested')
                <p class="text-sm text-gray-700">لتفعيل استقبال المدفوعات، يجب توثيق نشاطك التجاري.</p>
                <p class="text-sm text-gray-600">الحالة: <strong>غير موثق</strong></p>
                <form method="POST" action="{{ route('workspace.payments.merchant.request') }}">
                    @csrf
                    <button class="rounded-lg bg-[#06C2A4] px-4 py-2 text-sm font-semibold text-white">ابدأ توثيق النشاط</button>
                </form>
            @else
                <p class="text-sm text-gray-600">الحالة الحالية: <strong>{{ $verificationLabel }}</strong></p>
                @if($profile->rejection_reason)
                    <p class="text-sm text-red-700">سبب الرفض: {{ $profile->rejection_reason }}</p>
                @endif
            @endif
        </div>

        <div class="rounded-2xl border bg-white p-5 space-y-4">
            <h3 class="font-semibold text-gray-900">رفع مستند</h3>
            <form method="POST" action="{{ route('workspace.payments.merchant.upload') }}" enctype="multipart/form-data" class="space-y-3">
                @csrf
                <div>
                    <label class="mb-1 block text-sm font-semibold">نوع المستند</label>
                    <select name="document_type_code" required class="w-full rounded-lg border-gray-300">
                        @foreach($documentTypes as $type)
                            <option value="{{ $type->code }}">{{ $type->name_ar }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold">رقم المستند (اختياري)</label>
                    <input type="text" name="document_number" class="w-full rounded-lg border-gray-300" value="{{ old('document_number') }}">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold">تاريخ الانتهاء (اختياري)</label>
                    <input type="date" name="expires_at" class="w-full rounded-lg border-gray-300" value="{{ old('expires_at') }}">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold">الملف (PDF / JPEG / PNG / WEBP — حتى 8MB)</label>
                    <input type="file" name="document" required accept=".pdf,.jpg,.jpeg,.png,.webp" class="w-full text-sm">
                    <p class="mt-1 text-xs text-gray-500">المستندات خاصة وغير عامة. لا تُعرض كروابط عامة.</p>
                </div>
                <button class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white">رفع المستند</button>
            </form>
        </div>

        <div class="rounded-2xl border bg-white p-5 space-y-3">
            <div class="flex items-center justify-between gap-3">
                <h3 class="font-semibold text-gray-900">المستندات المرفوعة</h3>
                @if(in_array($profile->verification_status, ['documents_required', 'rejected', 'not_requested'], true))
                    <form method="POST" action="{{ route('workspace.payments.merchant.submit') }}">
                        @csrf
                        <button class="rounded-lg bg-[#067e6b] px-4 py-2 text-sm font-semibold text-white">إرسال للمراجعة</button>
                    </form>
                @endif
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-right">النوع</th>
                            <th class="px-3 py-2 text-right">الاسم</th>
                            <th class="px-3 py-2 text-right">الحالة</th>
                            <th class="px-3 py-2 text-right">عرض</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($documents as $document)
                            <tr>
                                <td class="px-3 py-2">{{ $documentTypes->firstWhere('code', $document->document_type_code)?->name_ar ?? $document->document_type_code }}</td>
                                <td class="px-3 py-2">{{ $document->original_name }}</td>
                                <td class="px-3 py-2">{{ \App\Support\MerchantStatusLabels::documentLabel($document->status) }}</td>
                                <td class="px-3 py-2">
                                    <a class="text-blue-600" href="{{ route('workspace.payments.merchant.documents.download', $document->id) }}">تحميل</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-4 text-center text-gray-500">لا توجد مستندات بعد.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
