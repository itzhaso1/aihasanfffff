@extends('layouts.financial', ['pageTitle' => 'الخزينة والتسويات'])

@section('content')
    <div class="space-y-6">
        <h2 class="text-xl font-bold">التحويلات والتسويات البنكية</h2>

        <div class="grid gap-4 xl:grid-cols-2">
            <form method="POST" action="{{ route('workspace.finance.treasury.transfers.store') }}" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                @csrf
                <h3 class="mb-3 text-sm font-bold">تحويل بين الحسابات</h3>
                <div class="grid gap-2 sm:grid-cols-2">
                    <select name="from_treasury_account_id" class="rounded-lg border-slate-300 text-sm" required>
                        @foreach($accounts as $account)
                            <option value="{{ $account->id }}">من: {{ $account->name }} ({{ number_format((float) $account->current_balance, 2) }})</option>
                        @endforeach
                    </select>
                    <select name="to_treasury_account_id" class="rounded-lg border-slate-300 text-sm" required>
                        @foreach($accounts as $account)
                            <option value="{{ $account->id }}">إلى: {{ $account->name }}</option>
                        @endforeach
                    </select>
                    <input type="number" step="0.01" min="0.01" name="amount" placeholder="المبلغ" class="rounded-lg border-slate-300 text-sm" required>
                    <input type="date" name="transfer_date" value="{{ now()->toDateString() }}" class="rounded-lg border-slate-300 text-sm" required>
                    <input type="text" name="reference" placeholder="مرجع اختياري لمنع التكرار" class="rounded-lg border-slate-300 text-sm sm:col-span-2">
                </div>
                <button class="mt-3 rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white">تنفيذ التحويل</button>
            </form>

            <form method="POST" action="{{ route('workspace.finance.treasury.statements.store') }}" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                @csrf
                <h3 class="mb-3 text-sm font-bold">كشف بنك جديد</h3>
                <div class="grid gap-2 sm:grid-cols-2">
                    <select name="treasury_account_id" class="rounded-lg border-slate-300 text-sm" required>
                        @foreach($accounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                        @endforeach
                    </select>
                    <input type="date" name="statement_date" value="{{ now()->toDateString() }}" class="rounded-lg border-slate-300 text-sm" required>
                    <input type="number" step="0.01" name="opening_balance" placeholder="افتتاحي" class="rounded-lg border-slate-300 text-sm" required>
                    <input type="number" step="0.01" name="closing_balance" placeholder="ختامي" class="rounded-lg border-slate-300 text-sm" required>
                </div>
                <button class="mt-3 rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white">إنشاء الكشف</button>
            </form>
        </div>

        <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <h3 class="border-b border-slate-100 px-4 py-3 text-sm font-bold">آخر التحويلات</h3>
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <tbody class="divide-y divide-slate-100">
                @forelse($transfers as $transfer)
                    <tr>
                        <td class="px-3 py-2">{{ $transfer->transfer_date?->toDateString() }}</td>
                        <td class="px-3 py-2">{{ $transfer->fromAccount?->name }} → {{ $transfer->toAccount?->name }}</td>
                        <td class="px-3 py-2 font-semibold">{{ number_format((float) $transfer->amount, 2) }}</td>
                    </tr>
                @empty
                    <tr><td class="px-3 py-6 text-center text-slate-500">لا تحويلات بعد.</td></tr>
                @endforelse
                </tbody>
            </table>
        </article>

        <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <h3 class="border-b border-slate-100 px-4 py-3 text-sm font-bold">كشوف البنوك</h3>
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <tbody class="divide-y divide-slate-100">
                @forelse($statements as $statement)
                    <tr>
                        <td class="px-3 py-2">{{ $statement->statement_date?->toDateString() }}</td>
                        <td class="px-3 py-2">{{ $statement->treasuryAccount?->name }}</td>
                        <td class="px-3 py-2">{{ $statement->status }}</td>
                        <td class="px-3 py-2"><a class="text-[#0f7668]" href="{{ route('workspace.finance.treasury.statements.show', $statement) }}">فتح</a></td>
                    </tr>
                @empty
                    <tr><td class="px-3 py-6 text-center text-slate-500">لا كشوف بعد.</td></tr>
                @endforelse
                </tbody>
            </table>
            <div class="p-3">{{ $statements->links() }}</div>
        </article>
    </div>
@endsection
