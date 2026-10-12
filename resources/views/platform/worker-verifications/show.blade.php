@extends('platform.layout')

@section('content')
<div dir="rtl" class="space-y-6">
    @include('platform.partials.nav')
    @include('partials.flash')

    <div class="flex items-center justify-between gap-3">
        <h1 class="text-xl font-bold text-gray-900">مراجعة عامل مستقل</h1>
        <a href="{{ route('platform.worker-verifications.index') }}" class="text-sm text-blue-600">العودة للطابور</a>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        <section class="rounded-xl border bg-white p-5 text-sm">
            <h2 class="mb-3 font-semibold">الملف المهني</h2>
            <p><span class="font-semibold">الاسم:</span> {{ $profile->display_name }}</p>
            <p><span class="font-semibold">البريد:</span> {{ $profile->user?->email }}</p>
            <p><span class="font-semibold">الدولة:</span> {{ $profile->country_code }}</p>
            <p><span class="font-semibold">الجنسية:</span> {{ $profile->nationality }}</p>
            <p><span class="font-semibold">خبرة المبيعات:</span> {{ $profile->sales_experience_level }}</p>
            <p><span class="font-semibold">التوفر:</span> {{ $profile->availability_status }}</p>
            <p class="mt-3 whitespace-pre-wrap"><span class="font-semibold">الوصف:</span> {{ $profile->professional_summary }}</p>
        </section>
        <section class="rounded-xl border bg-white p-5 text-sm">
            <h2 class="mb-3 font-semibold">الهوية الخاصة</h2>
            <p><span class="font-semibold">الاسم القانوني:</span> {{ $profile->privateIdentity?->legal_name }}</p>
            <p><span class="font-semibold">تاريخ الميلاد:</span> {{ $profile->privateIdentity?->date_of_birth?->format('Y-m-d') }}</p>
            <p><span class="font-semibold">رقم الهوية:</span> {{ $profile->privateIdentity?->identity_number }}</p>
            <p><span class="font-semibold">العنوان:</span> {{ $profile->privateIdentity?->address ?: '—' }}</p>
            <p class="mt-3"><span class="font-semibold">الحالة:</span> {{ $profile->verificationStatusLabel() }}</p>
            @if($profile->latestVerification?->rejection_reason)
                <p class="mt-2 text-red-700"><span class="font-semibold">ملاحظة المراجعة:</span> {{ $profile->latestVerification->rejection_reason }}</p>
            @endif
        </section>
    </div>

    <section class="rounded-xl border bg-white p-5">
        <h2 class="mb-3 font-semibold">مستندات خاصة</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-3 py-2 text-right">النوع</th>
                        <th class="px-3 py-2 text-right">الملف</th>
                        <th class="px-3 py-2 text-right">الحالة</th>
                        <th class="px-3 py-2 text-right">عرض آمن</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($documents as $document)
                        <tr>
                            <td class="px-3 py-2">{{ $document->document_type_code }}</td>
                            <td class="px-3 py-2">{{ $document->original_name }}</td>
                            <td class="px-3 py-2">{{ $document->status }}</td>
                            <td class="px-3 py-2">
                                <a class="text-blue-600" href="{{ \Illuminate\Support\Facades\URL::temporarySignedRoute('platform.worker-verifications.documents.download', now()->addMinutes(15), ['document' => $document->id]) }}">تحميل آمن</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-4 text-center text-gray-500">لا مستندات</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if($profile->verification_status === \App\Models\Worker\WorkerProfile::VERIFICATION_PENDING_REVIEW)
        @php
            $reviewTargets = [
                [
                    'type' => \App\Models\Worker\WorkerVerificationIssue::TARGET_PROFILE,
                    'id' => $profile->id,
                    'field' => 'display_name',
                    'label' => 'الاسم المعروض',
                ],
                [
                    'type' => \App\Models\Worker\WorkerVerificationIssue::TARGET_PROFILE,
                    'id' => $profile->id,
                    'field' => 'profile_photo',
                    'label' => 'الصورة الشخصية',
                ],
                [
                    'type' => \App\Models\Worker\WorkerVerificationIssue::TARGET_PROFILE,
                    'id' => $profile->id,
                    'field' => 'professional_summary',
                    'label' => 'الملخص المهني',
                ],
                [
                    'type' => \App\Models\Worker\WorkerVerificationIssue::TARGET_PROFILE,
                    'id' => $profile->id,
                    'field' => 'country_code',
                    'label' => 'الدولة',
                ],
                [
                    'type' => \App\Models\Worker\WorkerVerificationIssue::TARGET_PROFILE,
                    'id' => $profile->id,
                    'field' => 'nationality',
                    'label' => 'الجنسية',
                ],
                [
                    'type' => \App\Models\Worker\WorkerVerificationIssue::TARGET_PROFILE,
                    'id' => $profile->id,
                    'field' => 'sales_experience_level',
                    'label' => 'مستوى الخبرة',
                ],
                [
                    'type' => \App\Models\Worker\WorkerVerificationIssue::TARGET_PROFILE,
                    'id' => $profile->id,
                    'field' => 'availability_status',
                    'label' => 'التوفر',
                ],
                [
                    'type' => \App\Models\Worker\WorkerVerificationIssue::TARGET_SKILLS,
                    'id' => null,
                    'field' => 'skills',
                    'label' => 'المهارات',
                ],
                [
                    'type' => \App\Models\Worker\WorkerVerificationIssue::TARGET_LANGUAGES,
                    'id' => null,
                    'field' => 'languages',
                    'label' => 'اللغات',
                ],
            ];
            foreach ($profile->experiences as $experience) {
                $reviewTargets[] = [
                    'type' => \App\Models\Worker\WorkerVerificationIssue::TARGET_EXPERIENCE,
                    'id' => $experience->id,
                    'field' => 'experience',
                    'label' => 'الخبرة: '.$experience->title,
                ];
            }
            foreach ([
                'legal_name' => 'الاسم القانوني',
                'date_of_birth' => 'تاريخ الميلاد',
                'identity_number' => 'رقم الهوية',
                'address' => 'العنوان',
            ] as $field => $label) {
                $reviewTargets[] = [
                    'type' => \App\Models\Worker\WorkerVerificationIssue::TARGET_IDENTITY,
                    'id' => $profile->id,
                    'field' => $field,
                    'label' => $label,
                ];
            }
            foreach ($documents as $document) {
                $reviewTargets[] = [
                    'type' => \App\Models\Worker\WorkerVerificationIssue::TARGET_DOCUMENT,
                    'id' => $document->id,
                    'field' => $document->document_type_code,
                    'label' => 'المستند: '.$document->document_type_code.' — '.$document->original_name,
                ];
            }
        @endphp
        <div class="grid gap-4 md:grid-cols-2">
            <form method="POST" action="{{ route('platform.worker-verifications.approve', $profile->id) }}" class="rounded-xl border bg-white p-4 space-y-2">
                @csrf
                <h3 class="font-semibold text-green-700">اعتماد</h3>
                <textarea name="notes" rows="2" class="w-full rounded-lg border-gray-300" placeholder="ملاحظات اختيارية"></textarea>
                <button class="rounded-lg bg-green-600 px-4 py-2 text-sm text-white">اعتماد العامل</button>
            </form>
            <form method="POST" action="{{ route('platform.worker-verifications.reject', $profile->id) }}" class="rounded-xl border bg-white p-4 space-y-2">
                @csrf
                <h3 class="font-semibold text-red-700">رفض</h3>
                <textarea name="reason" required rows="2" class="w-full rounded-lg border-gray-300" placeholder="سبب الرفض"></textarea>
                <p class="text-xs text-gray-500">حدّد كل عنصر يحتاج إلى تعديل وأدخل سبباً عملياً له.</p>
                <div class="max-h-96 space-y-3 overflow-y-auto rounded-lg border border-red-100 bg-red-50/40 p-3">
                    @foreach($reviewTargets as $index => $target)
                        <div class="space-y-2 rounded-lg border border-red-100 bg-white p-3" data-review-issue-row>
                            <label class="flex items-center gap-2 text-sm font-semibold">
                                <input type="checkbox" data-review-issue-toggle>
                                {{ $target['label'] }}
                            </label>
                            <input type="hidden" name="issues[{{ $index }}][target_type]" value="{{ $target['type'] }}" data-review-issue-control disabled>
                            @if($target['id'] !== null)
                                <input type="hidden" name="issues[{{ $index }}][target_id]" value="{{ $target['id'] }}" data-review-issue-control disabled>
                            @endif
                            <input type="hidden" name="issues[{{ $index }}][field_key]" value="{{ $target['field'] }}" data-review-issue-control disabled>
                            <textarea name="issues[{{ $index }}][reason]" rows="2" class="w-full rounded-lg border-gray-300 text-sm" placeholder="سبب هذا العنصر والإجراء المطلوب" data-review-issue-control disabled></textarea>
                        </div>
                    @endforeach
                </div>
                <button class="rounded-lg bg-red-600 px-4 py-2 text-sm text-white">رفض</button>
            </form>
            <form method="POST" action="{{ route('platform.worker-verifications.request-documents', $profile->id) }}" class="rounded-xl border bg-white p-4 space-y-2">
                @csrf
                <h3 class="font-semibold text-amber-700">طلب مستندات</h3>
                <textarea name="reason" required rows="2" class="w-full rounded-lg border-gray-300" placeholder="ما المطلوب؟"></textarea>
                @if($documents->isNotEmpty())
                    <p class="text-xs text-gray-500">يمكن تحديد المستند الذي يحتاج إلى إعادة رفع.</p>
                    <div class="space-y-3 rounded-lg border border-amber-100 bg-amber-50/40 p-3">
                        @foreach($documents as $index => $document)
                            <div class="space-y-2 rounded-lg border border-amber-100 bg-white p-3" data-review-issue-row>
                                <label class="flex items-center gap-2 text-sm font-semibold">
                                    <input type="checkbox" data-review-issue-toggle>
                                    {{ $document->document_type_code }} — {{ $document->original_name }}
                                </label>
                                <input type="hidden" name="issues[{{ $index }}][target_type]" value="{{ \App\Models\Worker\WorkerVerificationIssue::TARGET_DOCUMENT }}" data-review-issue-control disabled>
                                <input type="hidden" name="issues[{{ $index }}][target_id]" value="{{ $document->id }}" data-review-issue-control disabled>
                                <input type="hidden" name="issues[{{ $index }}][field_key]" value="{{ $document->document_type_code }}" data-review-issue-control disabled>
                                <textarea name="issues[{{ $index }}][reason]" rows="2" class="w-full rounded-lg border-gray-300 text-sm" placeholder="ما الذي يجب تغييره؟" data-review-issue-control disabled></textarea>
                            </div>
                        @endforeach
                    </div>
                @endif
                <button class="rounded-lg bg-amber-600 px-4 py-2 text-sm text-white">طلب مستندات</button>
            </form>
        </div>
    @endif

    @if($profile->lifecycle_status !== \App\Models\Worker\WorkerProfile::LIFECYCLE_SUSPENDED)
        <form method="POST" action="{{ route('platform.worker-verifications.suspend', $profile->id) }}" class="rounded-xl border bg-white p-4 space-y-2">
            @csrf
            <h3 class="font-semibold text-slate-700">تعليق الحساب</h3>
            <textarea name="reason" required rows="2" class="w-full rounded-lg border-gray-300" placeholder="سبب التعليق"></textarea>
            <button class="rounded-lg bg-slate-700 px-4 py-2 text-sm text-white">تعليق العامل</button>
        </form>
    @endif
</div>
<script>
    document.querySelectorAll('[data-review-issue-toggle]').forEach((toggle) => {
        const row = toggle.closest('[data-review-issue-row]');
        const controls = row.querySelectorAll('[data-review-issue-control]');
        const sync = () => controls.forEach((control) => {
            control.disabled = !toggle.checked;
        });
        toggle.addEventListener('change', sync);
        sync();
    });
</script>
@endsection
