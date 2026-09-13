@extends('layouts.financial', ['pageTitle' => 'كشف حساب العميل'])

@section('content')
    <div class="space-y-6">
        <div>
            <h2 class="text-xl font-bold text-slate-900">كشف حساب العميل</h2>
            <p class="text-xs text-slate-500">يُبنى من فواتير المبيعات والدفعات وإشعارات الدائن/المدين الحالية</p>
        </div>

        <form method="GET" action="{{ route('workspace.finance.statements.show') }}" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-5">
            <div class="lg:col-span-2">
                <label class="mb-1 block text-xs font-semibold text-slate-600">العميل</label>
                <select name="customer_id" class="w-full rounded-lg border-slate-300 text-sm" required>
                    <option value="">اختر عميل</option>
                    @foreach($customers as $customer)
                        <option value="{{ $customer->id }}" @selected((string) old('customer_id', request('customer_id')) === (string) $customer->id)>{{ $customer->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold text-slate-600">من</label>
                <input type="date" name="from" value="{{ old('from', request('from', now()->startOfMonth()->toDateString())) }}" class="w-full rounded-lg border-slate-300 text-sm" required>
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold text-slate-600">إلى</label>
                <input type="date" name="to" value="{{ old('to', request('to', now()->toDateString())) }}" class="w-full rounded-lg border-slate-300 text-sm" required>
            </div>
            <div class="flex items-end gap-2">
                <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">عرض</button>
            </div>
        </form>

        @if($statement)
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('workspace.finance.statements.show', ['customer_id' => $statement['customer']->id, 'from' => $statement['from'], 'to' => $statement['to'], 'pdf' => 1]) }}" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">PDF</a>
                <a href="{{ route('workspace.finance.statements.show', ['customer_id' => $statement['customer']->id, 'from' => $statement['from'], 'to' => $statement['to'], 'csv' => 1]) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">CSV</a>
                <button type="button" onclick="window.print()" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">طباعة</button>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-xs text-slate-500">الرصيد الافتتاحي</p>
                    <p class="mt-1 text-xl font-extrabold">{{ number_format((float) $statement['opening_balance'], 2) }}</p>
                </article>
                <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-xs text-slate-500">الفواتير</p>
                    <p class="mt-1 text-xl font-extrabold">{{ number_format((float) $statement['invoices_total'], 2) }}</p>
                </article>
                <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-xs text-slate-500">الدفعات</p>
                    <p class="mt-1 text-xl font-extrabold">{{ number_format((float) $statement['payments_total'], 2) }}</p>
                </article>
                <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-xs text-slate-500">إشعارات دائن</p>
                    <p class="mt-1 text-xl font-extrabold">{{ number_format((float) $statement['credits_total'], 2) }}</p>
                </article>
                <article class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-xs text-slate-500">الرصيد الختامي</p>
                    <p class="mt-1 text-xl font-extrabold">{{ number_format((float) $statement['closing_balance'], 2) }}</p>
                </article>
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-5 py-3 text-sm font-bold">
                    كشف {{ $statement['customer']->name }} من {{ $statement['from'] }} إلى {{ $statement['to'] }}
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th class="px-4 py-2 text-right">التاريخ</th>
                                <th class="px-4 py-2 text-right">النوع</th>
                                <th class="px-4 py-2 text-right">المرجع</th>
                                <th class="px-4 py-2 text-right">الوصف</th>
                                <th class="px-4 py-2 text-right">مدين</th>
                                <th class="px-4 py-2 text-right">دائن</th>
                                <th class="px-4 py-2 text-right">الرصيد</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-t border-slate-100">
                                <td class="px-4 py-2" colspan="6">رصيد افتتاحي</td>
                                <td class="px-4 py-2 font-semibold">{{ number_format((float) $statement['opening_balance'], 2) }}</td>
                            </tr>
                            @forelse($statement['lines'] as $line)
                                <tr class="border-t border-slate-100">
                                    <td class="px-4 py-2">{{ $line['date'] }}</td>
                                    <td class="px-4 py-2">{{ $line['kind'] }}</td>
                                    <td class="px-4 py-2">
                                        @if(!empty($line['invoice_id']))
                                            <a class="text-[#06C2A4] hover:underline" href="{{ route('workspace.finance.invoices.show', $line['invoice_id']) }}">{{ $line['reference'] }}</a>
                                        @else
                                            {{ $line['reference'] }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">{{ $line['description'] }}</td>
                                    <td class="px-4 py-2">{{ number_format((float) $line['debit'], 2) }}</td>
                                    <td class="px-4 py-2">{{ number_format((float) $line['credit'], 2) }}</td>
                                    <td class="px-4 py-2 font-semibold">{{ number_format((float) $line['balance'], 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-8 text-center text-slate-500">لا توجد حركات في الفترة المحددة.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @else
            <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500">
                اختر عميلاً وفترة لعرض كشف الحساب.
            </div>
        @endif
    </div>
@endsection
