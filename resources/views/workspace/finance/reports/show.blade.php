@extends('layouts.financial', ['pageTitle' => 'تقرير مالي'])

@section('content')
    @php
        $titles = [
            'profit-loss' => 'الأرباح والخسائر',
            'balance-sheet' => 'الميزانية العمومية',
            'trial-balance' => 'ميزان المراجعة',
            'general-ledger' => 'دفتر الأستاذ',
            'ar-aging' => 'أعمار ذمم العملاء',
            'ap-aging' => 'أعمار ذمم الموردين',
            'cash-flow' => 'التدفق النقدي',
            'inventory-valuation' => 'تقييم المخزون',
        ];
    @endphp
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-xl font-bold">{{ $titles[$report] ?? 'تقرير' }}</h2>
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <input type="date" name="from" value="{{ $from }}" class="rounded-lg border-slate-300 text-sm">
                <input type="date" name="to" value="{{ $to }}" class="rounded-lg border-slate-300 text-sm">
                @if(!empty($accounts))
                    <select name="account_id" class="rounded-lg border-slate-300 text-sm">
                        <option value="">كل الحسابات</option>
                        @foreach($accounts as $account)
                            <option value="{{ $account->id }}" @selected((string) request('account_id') === (string) $account->id)>{{ $account->code }} {{ $account->name }}</option>
                        @endforeach
                    </select>
                @endif
                <button class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white">تحديث</button>
                <a href="{{ request()->fullUrlWithQuery(['format' => 'csv']) }}" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700">CSV</a>
            </form>
        </div>

        @if(!empty($profitAndLoss))
            <div class="grid gap-3 md:grid-cols-4">
                @foreach(['revenue' => 'الإيرادات', 'cogs' => 'تكلفة المبيعات', 'gross_profit' => 'مجمل الربح', 'net_profit' => 'صافي الربح'] as $key => $label)
                    <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p class="text-xs text-slate-500">{{ $label }}</p>
                        <p class="mt-2 text-2xl font-bold">{{ number_format((float) $profitAndLoss[$key], 2) }}</p>
                    </article>
                @endforeach
            </div>
            <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50"><tr><th class="px-3 py-2 text-right">الحساب</th><th class="px-3 py-2 text-right">النوع</th><th class="px-3 py-2 text-right">الرصيد</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    @foreach($profitAndLoss['rows'] as $row)
                        <tr><td class="px-3 py-2">{{ $row['code'] }} {{ $row['name'] }}</td><td class="px-3 py-2">{{ $row['type'] }}</td><td class="px-3 py-2">{{ number_format((float) $row['balance'], 2) }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </article>
        @endif

        @if(!empty($balanceSheet))
            <p class="text-sm {{ $balanceSheet['balanced'] ? 'text-emerald-700' : 'text-red-700' }}">
                الأصول {{ number_format((float) $balanceSheet['assets'], 2) }}
                · الالتزامات + حقوق الملكية {{ number_format((float) $balanceSheet['total_liabilities_and_equity'], 2) }}
                · أرباح الفترة {{ number_format((float) $balanceSheet['current_year_earnings'], 2) }}
            </p>
            <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50"><tr><th class="px-3 py-2 text-right">الحساب</th><th class="px-3 py-2 text-right">النوع</th><th class="px-3 py-2 text-right">الرصيد</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    @foreach($balanceSheet['rows'] as $row)
                        <tr><td class="px-3 py-2">{{ $row['code'] }} {{ $row['name'] }}</td><td class="px-3 py-2">{{ $row['type'] }}</td><td class="px-3 py-2">{{ number_format((float) $row['balance'], 2) }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </article>
        @endif

        @if(!empty($trialBalance))
            <p class="text-sm {{ $trialBalance['balanced'] ? 'text-emerald-700' : 'text-red-700' }}">
                مدين {{ number_format((float) $trialBalance['total_debit'], 2) }}
                · دائن {{ number_format((float) $trialBalance['total_credit'], 2) }}
            </p>
            <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50"><tr><th class="px-3 py-2 text-right">الحساب</th><th class="px-3 py-2 text-right">مدين</th><th class="px-3 py-2 text-right">دائن</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    @foreach($trialBalance['rows'] as $row)
                        <tr>
                            <td class="px-3 py-2">{{ $row['code'] }} {{ $row['name'] }}</td>
                            <td class="px-3 py-2">{{ number_format((float) $row['debit'], 2) }}</td>
                            <td class="px-3 py-2">{{ number_format((float) $row['credit'], 2) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </article>
        @endif

        @if(!empty($generalLedger))
            <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50"><tr><th class="px-3 py-2 text-right">التاريخ</th><th class="px-3 py-2 text-right">القيد</th><th class="px-3 py-2 text-right">الحساب</th><th class="px-3 py-2 text-right">مدين</th><th class="px-3 py-2 text-right">دائن</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    @forelse($generalLedger['lines'] as $line)
                        <tr>
                            <td class="px-3 py-2">{{ $line['date'] }}</td>
                            <td class="px-3 py-2">{{ $line['entry_number'] }}</td>
                            <td class="px-3 py-2">{{ $line['account_code'] }} {{ $line['account_name'] }}</td>
                            <td class="px-3 py-2">{{ number_format((float) $line['debit'], 2) }}</td>
                            <td class="px-3 py-2">{{ number_format((float) $line['credit'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-8 text-center text-slate-500">لا توجد قيود.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </article>
        @endif

        @if(!empty($aging))
            <div class="grid gap-3 md:grid-cols-5">
                @foreach($aging['buckets'] as $bucket => $amount)
                    <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p class="text-xs text-slate-500">{{ $bucket }}</p>
                        <p class="mt-2 text-xl font-bold">{{ number_format((float) $amount, 2) }}</p>
                    </article>
                @endforeach
            </div>
        @endif

        @if(!empty($cashFlow))
            <p class="text-sm text-slate-500">حركة حسابات الصندوق والبنك (1000 / 1100) من القيود المرحلة. هذا ليس بيان IAS 7 كاملًا بعد.</p>
            <div class="grid gap-3 md:grid-cols-3">
                @include('workspace.finance.partials.kpi-card', ['label' => 'افتتاحي', 'value' => $cashFlow['opening_cash']])
                @include('workspace.finance.partials.kpi-card', ['label' => 'صافي التغير', 'value' => $cashFlow['net_change'], 'tone' => ((float) $cashFlow['net_change'] >= 0 ? 'emerald' : 'rose')])
                @include('workspace.finance.partials.kpi-card', ['label' => 'ختامي', 'value' => $cashFlow['closing_cash'], 'tone' => 'indigo'])
            </div>
            <p class="text-xs {{ !empty($cashFlow['balanced']) ? 'text-emerald-700' : 'text-rose-700' }}">
                {{ !empty($cashFlow['balanced']) ? 'افتتاحي + التغير = الختامي.' : 'عدم تطابق بين الافتتاحي والتغير والختامي — راجع قيود الخزينة.' }}
            </p>
        @endif

        @if(!empty($inventoryValuation))
            <p class="text-sm font-semibold">إجمالي قيمة المخزون: {{ number_format((float) $inventoryValuation['total'], 2) }}</p>
            <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50"><tr><th class="px-3 py-2 text-right">المنتج</th><th class="px-3 py-2 text-right">الكمية</th><th class="px-3 py-2 text-right">التكلفة</th><th class="px-3 py-2 text-right">القيمة</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                    @foreach($inventoryValuation['rows'] as $row)
                        <tr>
                            <td class="px-3 py-2">{{ $row['name'] }}</td>
                            <td class="px-3 py-2">{{ $row['stock'] }}</td>
                            <td class="px-3 py-2">{{ number_format((float) $row['cost'], 2) }}</td>
                            <td class="px-3 py-2">{{ number_format((float) $row['value'], 2) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </article>
        @endif
    </div>
@endsection
