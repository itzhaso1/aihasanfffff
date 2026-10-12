<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">الملف المهني للعامل</h2></x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-4xl space-y-6 px-4">
            @include('workspace.partials.nav')
            @include('partials.flash')

            @if($errors->any())
                <div class="rounded-xl bg-red-50 p-4 text-sm text-red-700">
                    <ul class="list-inside list-disc space-y-1">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <section class="rounded-xl border bg-white p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-bold text-slate-900">{{ $worker['display_name'] }}</h1>
                        <p class="mt-1 text-sm text-slate-600">{{ $worker['country_code'] }} · {{ $worker['sales_experience_level'] }}</p>
                    </div>
                    <span class="rounded-full bg-emerald-50 px-3 py-1 text-sm font-semibold text-emerald-700">موثّق</span>
                </div>

                <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-2">
                    <div class="rounded-lg bg-slate-50 p-3">
                        <dt class="font-semibold">التوفر</dt>
                        <dd class="mt-1 text-slate-700">{{ \App\Models\Worker\WorkerProfile::availabilityLabel($worker['availability_status']) }}</dd>
                    </div>
                    <div class="rounded-lg bg-slate-50 p-3">
                        <dt class="font-semibold">نوع العرض</dt>
                        <dd class="mt-1 text-slate-700">ارتباط مستقل عبر المنصة</dd>
                    </div>
                </dl>

                <div class="mt-6 rounded-lg bg-slate-50 p-4 text-sm">
                    <h2 class="font-semibold">الملخص المهني</h2>
                    <p class="mt-2 whitespace-pre-line text-slate-700">{{ $worker['professional_summary'] }}</p>
                </div>

                @if(($worker['skills'] ?? []) !== [])
                    <div class="mt-4 text-sm text-slate-700">
                        المهارات: {{ collect($worker['skills'])->pluck('display_name')->join('، ') }}
                    </div>
                @endif
                @if(($worker['languages'] ?? []) !== [])
                    <div class="mt-2 text-sm text-slate-700">
                        اللغات: {{ collect($worker['languages'])->pluck('language_code')->join('، ') }}
                    </div>
                @endif
                @if(($worker['experiences'] ?? []) !== [])
                    <ul class="mt-4 space-y-2 text-sm text-slate-700">
                        @foreach($worker['experiences'] as $experience)
                            <li>{{ $experience['title'] }} — {{ $experience['organization_name'] }}</li>
                        @endforeach
                    </ul>
                @endif

                <p class="mt-6 rounded-lg bg-amber-50 p-4 text-sm text-amber-800">
                    تعرض هذه الصفحة بيانات مهنية مختارة فقط. لا تعرض بيانات الهوية الخاصة أو مستندات التحقق أو بيانات علاقات الشركات الأخرى.
                </p>
            </section>

            @if($worker['existing_engagement'] !== null)
                <section class="rounded-xl border bg-white p-6">
                    <h2 class="font-bold text-slate-900">العلاقة الحالية مع مساحة العمل</h2>
                    <p class="mt-2 text-sm text-slate-600">
                        الحالة: {{ \App\Enums\WorkerEngagementStatus::tryFrom((string) $worker['existing_engagement']['status'])?->label() ?? $worker['existing_engagement']['status'] }}
                    </p>
                    @if(in_array($worker['existing_engagement']['status'], ['draft', 'offered', 'accepted', 'active', 'paused'], true))
                        <a href="{{ route('workspace.worker-engagements.show', $worker['existing_engagement']['id']) }}" class="mt-4 inline-block text-sm font-semibold text-blue-700 underline">
                            عرض الارتباط الحالي
                        </a>
                    @endif
                </section>
            @endif

            @if($canManageEngagements && (
                $worker['existing_engagement'] === null
                || ! in_array($worker['existing_engagement']['status'], ['draft', 'offered', 'accepted', 'active', 'paused'], true)
            ))
                <section class="rounded-xl border bg-white p-6">
                    <h2 class="text-lg font-bold text-slate-900">بدء عرض الارتباط</h2>
                    <p class="mt-1 text-sm text-slate-600">سيستخدم هذا النموذج مسار عروض Phase 2B الحالي.</p>

                    <form
                        method="POST"
                        action="{{ route('workspace.worker-discovery.engagements.store', $worker['id']) }}"
                        class="mt-5 grid gap-4"
                        data-worker-contract-price-url="{{ route('workspace.worker-discovery.contract-price', $worker['id']) }}"
                    >
                        @csrf
                        <div>
                            <x-input-label for="job_title" value="الدور أو المسمى" />
                            <x-text-input id="job_title" class="mt-1 block w-full" name="job_title" :value="old('job_title')" />
                        </div>
                        <div>
                            <x-input-label for="job_description" value="وصف العمل" />
                            <textarea id="job_description" name="job_description" rows="4" maxlength="5000" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm" placeholder="اشرح للعامل المطلوب منه وما يجب إنجازه">{{ old('job_description') }}</textarea>
                            <p class="mt-1 text-xs text-slate-500">سيظهر هذا الوصف للعامل ضمن تفاصيل الارتباط.</p>
                        </div>
                        @include('workspace.worker-engagements._duration-fields')
                        @include('workspace.worker-engagements._guide-fields', ['guide' => null])
                        @include('workspace.worker-engagements._product-selection', ['products' => $products])
                        <div class="flex items-center justify-between gap-3">
                            <a href="{{ route('workspace.worker-discovery.index') }}" class="text-sm font-semibold text-slate-600 underline">العودة</a>
                            <button class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white">إرسال العرض</button>
                        </div>
                    </form>
                </section>
            @endif

            <a href="{{ route('workspace.worker-discovery.index') }}" class="inline-block text-sm font-semibold text-slate-600 underline">العودة إلى الاكتشاف</a>
        </div>
    </div>
</x-app-layout>
