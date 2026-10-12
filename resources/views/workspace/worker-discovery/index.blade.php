<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">اكتشاف العمال المستقلين</h2></x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4">
            @include('workspace.partials.nav')
            @include('partials.flash')

            <div class="rounded-xl border bg-white p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-bold text-slate-900">ملفات مهنية موثّقة</h1>
                        <p class="mt-1 text-sm text-slate-600">تظهر هنا الملفات التي اختار أصحابها إتاحتها للاكتشاف.</p>
                    </div>
                    <span class="rounded-full bg-amber-50 px-3 py-1 text-sm font-semibold text-amber-800">بدون ترتيب تفضيلي</span>
                </div>

                <form method="GET" action="{{ route('workspace.worker-discovery.index') }}" class="mt-6 grid gap-4 md:grid-cols-4">
                    <div class="md:col-span-2">
                        <x-input-label for="search" value="بحث مهني" />
                        <x-text-input id="search" class="mt-1 block w-full" name="search" :value="$filters['search'] ?? ''" placeholder="الاسم أو الملخص أو مستوى الخبرة" />
                    </div>
                    <div>
                        <x-input-label for="country_code" value="الدولة" />
                        <select id="country_code" name="country_code" class="mt-1 block w-full rounded-lg border-slate-300">
                            <option value="">كل الدول</option>
                            <option value="JO" @selected(($filters['country_code'] ?? '') === 'JO')>الأردن</option>
                            <option value="SA" @selected(($filters['country_code'] ?? '') === 'SA')>السعودية</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label for="availability_status" value="التوفر" />
                        <select id="availability_status" name="availability_status" class="mt-1 block w-full rounded-lg border-slate-300">
                            <option value="">كل الحالات</option>
                            <option value="available" @selected(($filters['availability_status'] ?? '') === 'available')>متاح</option>
                            <option value="limited" @selected(($filters['availability_status'] ?? '') === 'limited')>متاح بشكل محدود</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label for="sales_experience_level" value="مستوى الخبرة" />
                        <x-text-input id="sales_experience_level" class="mt-1 block w-full" name="sales_experience_level" :value="$filters['sales_experience_level'] ?? ''" placeholder="مثال: senior" />
                    </div>
                    <div>
                        <x-input-label for="skill_key" value="المهارة" />
                        <select id="skill_key" name="skill_key" class="mt-1 block w-full rounded-lg border-slate-300">
                            <option value="">كل المهارات</option>
                            @foreach($skills as $skill)
                                <option value="{{ $skill->skill_key }}" @selected(($filters['skill_key'] ?? '') === $skill->skill_key)>{{ $skill->display_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex items-end gap-3 md:col-span-3">
                        <button class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white">تطبيق البحث</button>
                        <a href="{{ route('workspace.worker-discovery.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">مسح</a>
                    </div>
                </form>
            </div>

            <section class="rounded-xl border bg-white p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-bold text-slate-900">العقود المفتوحة</h2>
                        <p class="mt-1 text-sm text-slate-600">العقود المقبولة أو النشطة أو المتوقفة مؤقتاً مع العمال المستقلين.</p>
                    </div>
                    <span class="rounded-full bg-emerald-50 px-3 py-1 text-sm font-semibold text-emerald-700">
                        {{ $openEngagements->count() }} عقد
                    </span>
                </div>

                @if($openEngagements->isEmpty())
                    <p class="mt-6 rounded-lg bg-slate-50 p-4 text-center text-sm text-slate-500">
                        لا توجد عقود مفتوحة حالياً.
                    </p>
                @else
                    <div class="mt-6 grid gap-4 md:grid-cols-2">
                        @foreach($openEngagements as $engagement)
                            @php
                                $durationUnit = $engagement->duration_unit?->value;
                                $durationUnitLabel = match ($durationUnit) {
                                    'day' => 'يوم',
                                    'week' => 'أسبوع',
                                    'month' => 'شهر',
                                    default => null,
                                };
                                $durationLabel = $engagement->duration_value !== null && $durationUnitLabel !== null
                                    ? $engagement->duration_value.' '.$durationUnitLabel
                                    : 'غير محددة';
                            @endphp
                            <article class="rounded-xl border border-slate-200 p-5">
                                <div class="flex items-start gap-3">
                                    @if(filled($engagement->workerProfile?->profile_photo))
                                        <img
                                            src="{{ \Illuminate\Support\Facades\Storage::disk('local')->url($engagement->workerProfile->profile_photo) }}"
                                            alt="{{ $engagement->workerProfile->display_name }}"
                                            class="h-14 w-14 rounded-full object-cover"
                                        >
                                    @else
                                        <div class="flex h-14 w-14 items-center justify-center rounded-full bg-emerald-50 text-lg font-bold text-emerald-700">
                                            {{ mb_substr($engagement->workerProfile?->display_name ?: 'ع', 0, 1) }}
                                        </div>
                                    @endif
                                    <div class="min-w-0 flex-1">
                                        <h3 class="truncate text-lg font-bold text-slate-900">
                                            {{ $engagement->workerProfile?->display_name ?: 'عامل مستقل' }}
                                        </h3>
                                        <p class="mt-1 truncate text-sm text-slate-600">
                                            {{ $engagement->job_title ?: 'تعاقد مستقل' }}
                                        </p>
                                    </div>
                                    <span class="shrink-0 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">
                                        {{ $engagement->status->label() }}
                                    </span>
                                </div>

                                <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-3">
                                    <div>
                                        <dt class="text-slate-500">تاريخ البداية</dt>
                                        <dd class="mt-1 font-semibold text-slate-800">{{ $engagement->starts_at?->toDateString() ?: 'غير محدد' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-slate-500">مدة التعاقد</dt>
                                        <dd class="mt-1 font-semibold text-slate-800">{{ $durationLabel }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-slate-500">تاريخ النهاية</dt>
                                        <dd class="mt-1 font-semibold text-slate-800">{{ $engagement->ends_at?->toDateString() ?: 'غير محدد' }}</dd>
                                    </div>
                                </dl>

                                <div class="mt-5 flex flex-wrap gap-2 text-xs text-slate-500">
                                    @if($engagement->guide !== null)
                                        <span class="rounded-full bg-slate-100 px-3 py-1">دليل عمل</span>
                                    @endif
                                    <span class="rounded-full bg-slate-100 px-3 py-1">
                                        {{ $engagement->granted_capabilities_count }} صلاحية
                                    </span>
                                    <span class="rounded-full bg-slate-100 px-3 py-1">
                                        {{ $engagement->assigned_products_count }} منتج
                                    </span>
                                </div>

                                <a
                                    href="{{ route('workspace.worker-engagements.show', $engagement) }}"
                                    class="mt-5 inline-block rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700"
                                >
                                    عرض تفاصيل التعاقد
                                </a>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>

            <div class="grid gap-4 md:grid-cols-2">
                @forelse($workers as $worker)
                    <article class="rounded-xl border bg-white p-6">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h2 class="text-lg font-bold text-slate-900">{{ $worker['display_name'] }}</h2>
                                <p class="mt-1 text-sm text-slate-600">{{ $worker['country_code'] }} · {{ $worker['sales_experience_level'] }}</p>
                            </div>
                            <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">موثّق</span>
                        </div>

                        <p class="mt-4 line-clamp-3 text-sm text-slate-700">{{ $worker['professional_summary'] }}</p>
                        <p class="mt-3 text-sm text-slate-500">التوفر: {{ \App\Models\Worker\WorkerProfile::availabilityLabel($worker['availability_status']) }}</p>

                        @if($worker['existing_engagement'] !== null)
                            <p class="mt-3 rounded-lg bg-slate-50 p-3 text-sm text-slate-700">
                                حالة الارتباط الحالي: {{ \App\Enums\WorkerEngagementStatus::tryFrom((string) $worker['existing_engagement']['status'])?->label() ?? $worker['existing_engagement']['status'] }}
                            </p>
                        @endif

                        <a href="{{ route('workspace.worker-discovery.show', $worker['id']) }}" class="mt-5 inline-block rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">
                            عرض الملف المهني
                        </a>
                    </article>
                @empty
                    <div class="rounded-xl border bg-white p-6 text-sm text-slate-500 md:col-span-2">
                        لا توجد ملفات مهنية مطابقة للبحث.
                    </div>
                @endforelse
            </div>

            <div>
                {{ $workers->links() }}
            </div>
        </div>
    </div>
</x-app-layout>
