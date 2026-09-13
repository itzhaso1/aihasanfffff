@extends('layouts.financial', ['pageTitle' => 'عروض الأسعار'])

@section('content')
    @php
        $statusLabels = [
            'draft' => 'مسودة',
            'issued' => 'صادر',
            'cancelled' => 'ملغى',
        ];
        $pipeline = $pipeline ?? [];
    @endphp
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="text-xl font-black text-slate-900">عروض الأسعار</h2>
                <p class="text-sm text-slate-500">مستند مبيعات تقديري. ليس فاتورة ولا يُرحّل محاسبيًا ولا يدخل سلسلة الفوترة الإلكترونية.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('workspace.finance.exports.download', 'quotes') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">CSV</a>
                <a href="{{ route('workspace.finance.quotes.create') }}" class="rounded-lg bg-[#06C2A4] px-4 py-2 text-sm font-semibold text-white hover:bg-[#05ab91]">إنشاء عرض سعر</a>
            </div>
        </div>

        <div class="flex gap-2 overflow-x-auto pb-1">
            <a href="{{ route('workspace.finance.quotes.index') }}" class="shrink-0 rounded-full px-3 py-1.5 text-xs font-bold {{ request('status') === null || request('status') === '' ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200' }}">الكل ({{ $pipeline['all'] ?? 0 }})</a>
            @foreach($statusLabels as $key => $label)
                <a href="{{ route('workspace.finance.quotes.index', ['status' => $key]) }}" class="shrink-0 rounded-full px-3 py-1.5 text-xs font-bold {{ request('status') === $key ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200' }}">{{ $label }} ({{ $pipeline[$key] ?? 0 }})</a>
            @endforeach
        </div>

        <form method="GET" class="flex gap-2">
            <input type="hidden" name="status" value="{{ request('status') }}">
            <input name="search" value="{{ request('search') }}" class="rounded-lg border-slate-300 text-sm" placeholder="بحث بالرقم أو اسم العميل">
            <button class="rounded-lg bg-slate-800 px-3 py-2 text-sm text-white">بحث</button>
        </form>

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="px-4 py-3 text-right">الرقم</th>
                        <th class="px-4 py-3 text-right">العميل</th>
                        <th class="px-4 py-3 text-right">الحالة</th>
                        <th class="px-4 py-3 text-right">التاريخ</th>
                        <th class="px-4 py-3 text-right">الصلاحية</th>
                        <th class="px-4 py-3 text-right">الإجمالي</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($quotes as $quote)
                        <tr>
                            <td class="px-4 py-3 font-semibold"><a href="{{ route('workspace.finance.quotes.show', $quote) }}" class="text-[#06C2A4] hover:underline">{{ $quote->quote_number }}</a></td>
                            <td class="px-4 py-3">{{ $quote->customer?->name ?? '-' }}</td>
                            <td class="px-4 py-3">{{ $statusLabels[$quote->status] ?? $quote->status }}</td>
                            <td class="px-4 py-3">{{ $quote->issue_date?->format('Y-m-d') }}</td>
                            <td class="px-4 py-3">{{ $quote->expiry_date?->format('Y-m-d') ?: '-' }}</td>
                            <td class="px-4 py-3 font-semibold">{{ number_format((float) $quote->total, 2) }} {{ $quote->currency }}</td>
                            <td class="px-4 py-3 text-left">
                                <a href="{{ route('workspace.finance.quotes.pdf', $quote) }}" class="text-slate-600 hover:underline">PDF</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">لا توجد عروض أسعار.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $quotes->links() }}</div>
    </div>
@endsection
