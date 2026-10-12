@extends('platform.layout')

@section('content')
<div dir="rtl" class="space-y-6">
    @include('platform.partials.nav')

    <div>
        <h1 class="text-xl font-bold text-gray-900">أسعار تعاقد العمال</h1>
        <p class="mt-1 text-sm text-gray-600">
            أسعار المنصة حسب العامل ومدة التعاقد. لا تعدّل الشركة أو العامل هذه الأسعار.
        </p>
    </div>

    @include('partials.flash')

    <section class="rounded-xl border bg-white p-5">
        <h2 class="font-semibold text-gray-900">إضافة سعر مستقبلي</h2>
        <form method="POST" action="{{ route('platform.worker-contract-rates.store') }}" class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
            @csrf
            <label class="text-sm text-gray-700 lg:col-span-2">
                العامل
                <select name="worker_profile_id" required class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                    <option value="">اختر العامل</option>
                    @foreach($workers as $worker)
                        <option value="{{ $worker->id }}" @selected((string) old('worker_profile_id') === (string) $worker->id)>
                            #{{ $worker->id }} — {{ $worker->display_name ?: $worker->user?->name ?: 'عامل مستقل' }}
                        </option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm text-gray-700">
                القيمة
                <input name="duration_value" type="number" min="1" value="{{ old('duration_value', 1) }}" required class="mt-1 w-full rounded-lg border-gray-300 text-sm">
            </label>
            <label class="text-sm text-gray-700">
                الوحدة
                <select name="duration_unit" required class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                    @foreach(['day' => 'يوم', 'week' => 'أسبوع', 'month' => 'شهر'] as $unit => $label)
                        <option value="{{ $unit }}" @selected(old('duration_unit', 'day') === $unit)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-sm text-gray-700">
                السعر
                <input name="amount" type="number" min="0" step="0.01" value="{{ old('amount') }}" required class="mt-1 w-full rounded-lg border-gray-300 text-sm">
            </label>
            <label class="text-sm text-gray-700">
                العملة
                <input name="currency" type="text" maxlength="3" value="{{ old('currency', 'SAR') }}" required class="mt-1 w-full rounded-lg border-gray-300 text-sm uppercase">
            </label>
            <label class="text-sm text-gray-700 lg:col-span-2">
                يبدأ من
                <input name="effective_from" type="datetime-local" value="{{ old('effective_from') }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
            </label>
            <button class="self-end rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white lg:col-span-2">
                حفظ سعر المنصة
            </button>
        </form>
    </section>

    <section class="overflow-x-auto rounded-xl border bg-white">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-right">العامل</th>
                    <th class="px-4 py-3 text-right">المدة</th>
                    <th class="px-4 py-3 text-right">السعر</th>
                    <th class="px-4 py-3 text-right">الفترة</th>
                    <th class="px-4 py-3 text-right">الحالة</th>
                    <th class="px-4 py-3 text-right"></th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse($rates as $rate)
                    <tr>
                        <td class="px-4 py-3">
                            #{{ $rate->worker_profile_id }} —
                            {{ $rate->workerProfile?->display_name ?: $rate->workerProfile?->user?->name ?: 'عامل مستقل' }}
                        </td>
                        <td class="px-4 py-3">
                            {{ $rate->duration_value }}
                            {{ ['day' => 'يوم', 'week' => 'أسبوع', 'month' => 'شهر'][$rate->duration_unit] ?? $rate->duration_unit }}
                        </td>
                        <td class="px-4 py-3 font-semibold">
                            {{ \App\Support\Money\Money::fromMinor((int) $rate->amount_minor) }} {{ $rate->currency }}
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-500">
                            {{ $rate->effective_from?->format('Y-m-d H:i') ?: 'فوري' }}
                            —
                            {{ $rate->effective_until?->format('Y-m-d H:i') ?: 'مفتوح' }}
                        </td>
                        <td class="px-4 py-3">{{ $rate->is_active ? 'نشط' : 'معطل' }}</td>
                        <td class="px-4 py-3">
                            @if($rate->is_active)
                                <form method="POST" action="{{ route('platform.worker-contract-rates.disable', $rate) }}">
                                    @csrf
                                    <button class="text-sm font-semibold text-red-700">تعطيل</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">لا توجد أسعار تعاقد.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
@endsection
