@extends('platform.layout')

@section('content')
<div dir="rtl" class="space-y-6">
    @include('platform.partials.nav')
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

    <div>
        <h1 class="text-xl font-bold text-gray-900">سجلات صرف العمال المستقلين</h1>
        <p class="mt-1 text-sm text-gray-600">سجل provider-agnostic فقط؛ لا يتم تنفيذ تحويل مالي خارجي من هذه الصفحة.</p>
    </div>

    <div class="overflow-x-auto rounded-xl border bg-white">
        <div class="border-b p-4">
            <h2 class="font-bold text-gray-900">فترات معتمدة قابلة للصرف</h2>
        </div>
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-right">الفترة</th>
                    <th class="px-4 py-3 text-right">العامل</th>
                    <th class="px-4 py-3 text-right">الشركة</th>
                    <th class="px-4 py-3 text-right"></th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse($approvedPeriods as $period)
                    <tr>
                        <td class="px-4 py-3">
                            #{{ $period->id }}
                            <span class="block text-xs text-gray-500">{{ $period->starts_at?->format('Y-m-d') }} — {{ $period->ends_at?->format('Y-m-d') }}</span>
                        </td>
                        <td class="px-4 py-3">{{ $period->engagement?->workerProfile?->display_name }}</td>
                        <td class="px-4 py-3">{{ $period->engagement?->workspace?->name }}</td>
                        <td class="px-4 py-3">
                            <form method="POST" action="{{ route('platform.worker-payouts.store') }}">
                                @csrf
                                <input type="hidden" name="period_id" value="{{ $period->id }}">
                                <button class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white">إنشاء سجل معلّق</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500">لا توجد فترات معتمدة.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="overflow-x-auto rounded-xl border bg-white">
        <div class="border-b p-4">
            <h2 class="font-bold text-gray-900">السجل</h2>
        </div>
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-right">العامل / الشركة</th>
                    <th class="px-4 py-3 text-right">الفترة</th>
                    <th class="px-4 py-3 text-right">المبلغ</th>
                    <th class="px-4 py-3 text-right">الحالة</th>
                    <th class="px-4 py-3 text-right">الإجراء</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse($payouts as $payout)
                    <tr>
                        <td class="px-4 py-3">
                            {{ $payout->workerProfile?->display_name }}
                            <span class="block text-xs text-gray-500">{{ $payout->engagement?->workspace?->name }}</span>
                        </td>
                        <td class="px-4 py-3">#{{ $payout->worker_earning_period_id }}</td>
                        <td class="px-4 py-3">{{ \App\Support\Money\Money::fromMinor((int) $payout->amount_minor) }} {{ $payout->currency }}</td>
                        <td class="px-4 py-3">{{ $payout->status->label() }}</td>
                        <td class="px-4 py-3">
                            @if(! $payout->status->isTerminal())
                                <form method="POST" action="{{ route('platform.worker-payouts.update', $payout) }}" class="flex min-w-72 flex-wrap gap-2">
                                    @csrf
                                    @method('PATCH')
                                    <select name="status" required class="rounded-lg border-gray-300 text-xs">
                                        @foreach(\App\Enums\WorkerPayoutStatus::cases() as $status)
                                            @if($payout->status->canTransitionTo($status))
                                                <option value="{{ $status->value }}">{{ $status->label() }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                    <input name="provider_reference" maxlength="191" placeholder="مرجع عام عند paid" class="rounded-lg border-gray-300 text-xs">
                                    <input name="reason" maxlength="2000" placeholder="سبب failed/cancelled" class="rounded-lg border-gray-300 text-xs">
                                    <button class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white">حفظ</button>
                                </form>
                            @else
                                <span class="text-xs text-gray-500">سجل نهائي غير قابل للتعديل</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">لا توجد سجلات صرف.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
