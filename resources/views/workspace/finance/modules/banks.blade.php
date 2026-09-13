@extends('layouts.financial', ['pageTitle' => 'النقد والبنوك'])

@section('content')
    <div class="space-y-4">
        <h2 class="text-xl font-bold text-slate-900">الحسابات النقدية والبنكية</h2>

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="px-3 py-3 text-right">الاسم</th>
                        <th class="px-3 py-3 text-right">النوع</th>
                        <th class="px-3 py-3 text-right">البنك</th>
                        <th class="px-3 py-3 text-right">IBAN</th>
                        <th class="px-3 py-3 text-right">الرصيد</th>
                        <th class="px-3 py-3 text-right">الحساب المحاسبي</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($treasuryAccounts as $account)
                        <tr>
                            <td class="px-3 py-3 font-semibold">{{ $account->name }}</td>
                            <td class="px-3 py-3">{{ $account->type }}</td>
                            <td class="px-3 py-3">{{ $account->bank_name ?? '-' }}</td>
                            <td class="px-3 py-3">{{ $account->iban ?? '-' }}</td>
                            <td class="px-3 py-3">{{ number_format((float) $account->current_balance, 2) }} {{ $account->currency }}</td>
                            <td class="px-3 py-3">{{ $account->linkedAccount?->code }} {{ $account->linkedAccount?->name }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-8 text-center text-slate-500">لا توجد حسابات خزينة بعد.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $treasuryAccounts->links() }}</div>
    </div>
@endsection
