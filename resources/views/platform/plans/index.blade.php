@extends('platform.layout')

@section('content')
    <div class="py-8" dir="rtl">
        <div class="mx-auto max-w-7xl space-y-6 px-4">
            @include('platform.partials.nav')
            @include('partials.flash')

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-slate-900">إدارة الباقات</h1>
                    <p class="mt-1 text-sm text-slate-500">المصفوفة أدناه تُقرأ من جدول الباقات في قاعدة البيانات (نفس مصدر FeatureAccessService).</p>
                </div>
                <a href="{{ route('platform.plans.create') }}" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white">إضافة باقة</a>
            </div>

            <section class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-4 py-3">
                    <h2 class="text-base font-semibold text-slate-900">مصفوفة المنتج القياسية</h2>
                    <p class="text-xs text-slate-500">Starter · Pro · Business · Enterprise</p>
                </div>
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-4 py-3 text-right font-semibold">الميزة</th>
                            @foreach(['starter' => 'Starter', 'pro' => 'Pro', 'business' => 'Business', 'enterprise' => 'Enterprise'] as $tier => $label)
                                <th class="px-4 py-3 text-center font-semibold">
                                    {{ $label }}
                                    @if(!empty($matrixPlans[$tier]))
                                        <div class="mt-1 text-[10px] font-normal text-slate-400">{{ $matrixPlans[$tier]->code }}</div>
                                    @else
                                        <div class="mt-1 text-[10px] font-normal text-amber-600">غير مُعرّفة</div>
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($comparisonRows as $row)
                            <tr>
                                <td class="px-4 py-3 font-medium text-slate-800">{{ $row['label'] }}</td>
                                @foreach(['starter', 'pro', 'business', 'enterprise'] as $tier)
                                    @php
                                        $plan = $matrixPlans[$tier] ?? null;
                                        $features = is_array($plan?->features) ? $plan->features : [];
                                        $aliases = config('plans.feature_aliases.'.$row['key'], []);
                                        $enabled = in_array($row['key'], $features, true);
                                        if (! $enabled && is_array($aliases)) {
                                            foreach ($aliases as $alias) {
                                                if (in_array($alias, $features, true)) {
                                                    $enabled = true;
                                                    break;
                                                }
                                            }
                                        }
                                    @endphp
                                    <td class="px-4 py-3 text-center text-lg {{ $enabled ? 'text-emerald-600' : 'text-slate-300' }}">
                                        {{ $enabled ? '✓' : '—' }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>

            @if($addons->isNotEmpty())
                <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h2 class="mb-3 text-base font-semibold text-slate-900">الإضافات (Add-ons)</h2>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach($addons as $addon)
                            <article class="rounded-xl border border-slate-100 bg-slate-50 p-3 text-sm">
                                <p class="font-semibold text-slate-900">{{ $addon->name }}</p>
                                <p class="text-xs text-slate-500">{{ $addon->code }} · {{ number_format((float) $addon->price, 2) }} {{ $addon->currency }}</p>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-4 py-3">
                    <h2 class="text-base font-semibold text-slate-900">كل الباقات (بما فيها Legacy)</h2>
                </div>
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 text-right">الاسم</th>
                            <th class="px-4 py-3 text-right">الكود</th>
                            <th class="px-4 py-3 text-right">المستوى</th>
                            <th class="px-4 py-3 text-right">النوع</th>
                            <th class="px-4 py-3 text-right">السعر</th>
                            <th class="px-4 py-3 text-right">الميزات</th>
                            <th class="px-4 py-3 text-right">الحالة</th>
                            <th class="px-4 py-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse($plans as $plan)
                            <tr>
                                <td class="px-4 py-3">{{ $plan->name }}</td>
                                <td class="px-4 py-3 font-mono text-xs">{{ $plan->code }}</td>
                                <td class="px-4 py-3">{{ $plan->tier ?: '—' }}</td>
                                <td class="px-4 py-3">{{ $plan->workspace_type }}</td>
                                <td class="px-4 py-3">{{ number_format((float) $plan->price, 2) }} {{ $plan->currency }}</td>
                                <td class="px-4 py-3">{{ count($plan->features ?? []) }}</td>
                                <td class="px-4 py-3">
                                    <span class="{{ $plan->is_active ? 'text-emerald-700' : 'text-slate-400' }}">
                                        {{ $plan->is_active ? 'نشط' : 'غير نشط' }}
                                    </span>
                                    @if($plan->is_public)
                                        <span class="mr-2 text-[11px] text-blue-600">عامة</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-left">
                                    <a href="{{ route('platform.plans.edit', $plan) }}" class="text-blue-600">تعديل</a>
                                    <form method="POST" action="{{ route('platform.plans.destroy', $plan) }}" class="inline">
                                        @csrf @method('DELETE')
                                        <button class="mr-3 text-red-600" onclick="return confirm('حذف الباقة؟')">حذف</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-4 py-6 text-center text-gray-500">لا توجد باقات.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
            <div class="mt-4">{{ $plans->links() }}</div>
        </div>
    </div>
@endsection
