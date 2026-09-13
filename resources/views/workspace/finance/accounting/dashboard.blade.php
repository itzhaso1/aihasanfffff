@extends('layouts.financial', ['pageTitle' => 'المحاسبة'])

@section('content')
    <div class="space-y-4">
        <h2 class="text-xl font-bold text-slate-900">لوحة المحاسبة</h2>

        <div class="grid gap-4 lg:grid-cols-2">
            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold">ميزان المراجعة</h3>
                <div class="mb-3 flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 text-sm">
                    <span>إجمالي المدين: {{ number_format((float) $trialTotals['debit'], 2) }}</span>
                    <span>إجمالي الدائن: {{ number_format((float) $trialTotals['credit'], 2) }}</span>
                </div>
                <div class="max-h-96 overflow-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th class="px-2 py-2 text-right">الكود</th>
                                <th class="px-2 py-2 text-right">الحساب</th>
                                <th class="px-2 py-2 text-right">النوع</th>
                                <th class="px-2 py-2 text-right">مدين</th>
                                <th class="px-2 py-2 text-right">دائن</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($trialBalance as $row)
                                <tr>
                                    <td class="px-2 py-2">{{ $row->code }}</td>
                                    <td class="px-2 py-2">{{ $row->name }}</td>
                                    <td class="px-2 py-2">{{ $row->type }}</td>
                                    <td class="px-2 py-2">{{ number_format((float) $row->debit_total, 2) }}</td>
                                    <td class="px-2 py-2">{{ number_format((float) $row->credit_total, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h3 class="mb-3 text-sm font-bold">آخر القيود اليومية</h3>
                <div class="space-y-2">
                    @forelse($entries as $entry)
                        <details class="rounded-xl border border-slate-200 p-3">
                            <summary class="cursor-pointer list-none">
                                <div class="flex items-center justify-between">
                                    <span class="font-semibold">{{ $entry->entry_number }} — {{ $entry->type }}</span>
                                    <span class="text-xs text-slate-500">{{ $entry->entry_date }}</span>
                                </div>
                            </summary>
                            <div class="mt-2 space-y-1 border-t border-slate-200 pt-2 text-sm">
                                @foreach($entry->lines as $line)
                                    <div class="flex items-center justify-between rounded-md bg-slate-50 px-2 py-1">
                                        <span>{{ $line->account?->code }} - {{ $line->account?->name }}</span>
                                        <span>Dr {{ number_format((float) $line->debit, 2) }} | Cr {{ number_format((float) $line->credit, 2) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @empty
                        <p class="text-sm text-slate-500">لا توجد قيود حتى الآن.</p>
                    @endforelse
                </div>
                <div class="mt-3">{{ $entries->links() }}</div>
            </article>
        </div>

        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 class="mb-3 text-sm font-bold">Cash Flow (آخر 6 أشهر)</h3>
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @forelse($monthlyCashFlow as $row)
                    <div class="rounded-lg border border-slate-200 p-3 text-sm">
                        <p class="font-semibold text-slate-900">{{ $row->month }}</p>
                        <p class="text-slate-600">{{ number_format((float) $row->inflow, 2) }}</p>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">لا توجد بيانات تدفق نقدي بعد.</p>
                @endforelse
            </div>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h3 class="mb-3 text-sm font-bold">دليل الحسابات</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-2 py-2 text-right">الكود</th>
                            <th class="px-2 py-2 text-right">الاسم</th>
                            <th class="px-2 py-2 text-right">النوع</th>
                            <th class="px-2 py-2 text-right">مدين إجمالي</th>
                            <th class="px-2 py-2 text-right">دائن إجمالي</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($accounts as $account)
                            <tr>
                                <td class="px-2 py-2">{{ $account->code }}</td>
                                <td class="px-2 py-2">{{ $account->name }}</td>
                                <td class="px-2 py-2">{{ $account->type }}</td>
                                <td class="px-2 py-2">{{ number_format((float) ($account->debit_total ?? 0), 2) }}</td>
                                <td class="px-2 py-2">{{ number_format((float) ($account->credit_total ?? 0), 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $accounts->links() }}</div>
        </article>
    </div>
@endsection
