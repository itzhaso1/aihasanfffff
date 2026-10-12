@extends('platform.layout')

@section('content')
<div dir="rtl" class="space-y-6">
    @include('platform.partials.nav')

    <div>
        <h1 class="text-xl font-bold text-gray-900">عمليات العمال المستقلين</h1>
        <p class="mt-1 text-sm text-gray-600">مؤشرات تشغيلية مجمعة من سجلات العمال والارتباطات الحالية.</p>
    </div>

    <form method="GET" class="grid gap-3 rounded-xl border bg-white p-4 sm:grid-cols-3">
        <label class="text-sm text-gray-700">
            حالة الارتباط
            <select name="status" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                <option value="">كل الحالات</option>
                @foreach(\App\Enums\WorkerEngagementStatus::cases() as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm text-gray-700">
            عدد النتائج
            <select name="per_page" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                @foreach([20, 50] as $pageSize)
                    <option value="{{ $pageSize }}" @selected((int) request('per_page', 20) === $pageSize)>{{ $pageSize }}</option>
                @endforeach
            </select>
        </label>
        <button class="self-end rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white">تطبيق</button>
    </form>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach([
            'verified_workers' => 'عمال موثقون',
            'discoverable_workers' => 'عمال قابلون للاكتشاف',
            'active_engagements' => 'ارتباطات نشطة',
            'pending_offers' => 'عروض معلقة',
            'active_workers' => 'عمال نشطون',
            'suspended_workers' => 'عمال موقوفون',
        ] as $key => $label)
            <div class="rounded-xl border bg-white p-5">
                <p class="text-sm text-gray-500">{{ $label }}</p>
                <p class="mt-2 text-2xl font-bold text-gray-900">{{ $metrics[$key] }}</p>
            </div>
        @endforeach
    </div>

    <section class="rounded-xl border bg-white p-5">
        <h2 class="font-bold text-gray-900">حالات سجلات الصرف</h2>
        <div class="mt-4 flex flex-wrap gap-3 text-sm">
            @forelse($payoutStatuses as $status => $count)
                <span class="rounded-full bg-gray-100 px-3 py-1">{{ \App\Enums\WorkerPayoutStatus::tryFrom((string) $status)?->label() ?? $status }}: {{ $count }}</span>
            @empty
                <span class="text-gray-500">لا توجد سجلات صرف.</span>
            @endforelse
        </div>
    </section>

    <section class="overflow-x-auto rounded-xl border bg-white">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-right">العامل</th>
                    <th class="px-4 py-3 text-right">الشركة</th>
                    <th class="px-4 py-3 text-right">الحالة</th>
                    <th class="px-4 py-3 text-right">الفترة</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse($engagements as $engagement)
                    <tr>
                        <td class="px-4 py-3">
                            {{ $engagement->workerProfile?->display_name ?: 'عامل مستقل' }}
                            <span class="block text-xs text-gray-500">{{ $engagement->job_title ?: 'بدون مسمى' }}</span>
                        </td>
                        <td class="px-4 py-3">{{ $engagement->workspace?->name ?: '-' }}</td>
                        <td class="px-4 py-3">{{ $engagement->status->label() }}</td>
                        <td class="px-4 py-3">
                            {{ $engagement->starts_at?->format('Y-m-d') ?: '-' }}
                            —
                            {{ $engagement->ends_at?->format('Y-m-d') ?: '-' }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500">لا توجد ارتباطات.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="border-t p-4">{{ $engagements->links() }}</div>
    </section>
</div>
@endsection
