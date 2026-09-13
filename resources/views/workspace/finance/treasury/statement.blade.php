@extends('layouts.financial', ['pageTitle' => 'تسوية كشف بنك'])

@section('content')
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="text-xl font-bold">{{ $statement->treasuryAccount?->name }} — {{ $statement->statement_date?->toDateString() }}</h2>
                <p class="text-sm text-slate-500">الحالة: {{ $statement->status }}</p>
            </div>
            <div class="flex gap-2">
                <form method="POST" action="{{ route('workspace.finance.treasury.statements.suggest', $statement) }}">@csrf<button class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold">اقتراح مطابقة</button></form>
                <form method="POST" action="{{ route('workspace.finance.treasury.statements.complete', $statement) }}">@csrf<button class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white">إكمال التسوية</button></form>
            </div>
        </div>

        <form method="POST" action="{{ route('workspace.finance.treasury.statements.lines.store', $statement) }}" class="rounded-2xl border border-slate-200 bg-white p-4">
            @csrf
            <label class="mb-1 block text-xs font-semibold">حركات JSON</label>
            <textarea name="lines_json" rows="4" class="w-full rounded-lg border-slate-300 text-sm" placeholder='[{"posted_date":"{{ now()->toDateString() }}","amount":100,"description":"Deposit"}]'></textarea>
            <button class="mt-2 rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white">إضافة</button>
        </form>

        <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50"><tr><th class="px-3 py-2 text-right">التاريخ</th><th class="px-3 py-2 text-right">الوصف</th><th class="px-3 py-2 text-right">المبلغ</th><th class="px-3 py-2 text-right">الحالة</th><th class="px-3 py-2 text-right">مطابقة</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse($statement->lines as $line)
                    <tr>
                        <td class="px-3 py-2">{{ $line->posted_date?->toDateString() }}</td>
                        <td class="px-3 py-2">{{ $line->description }}</td>
                        <td class="px-3 py-2">{{ number_format((float) $line->amount, 2) }}</td>
                        <td class="px-3 py-2">{{ $line->status }} @if($line->suggestion_reason)<span class="block text-xs text-slate-500">{{ $line->suggestion_reason }} ({{ $line->suggestion_confidence }}%)</span>@endif</td>
                        <td class="px-3 py-2">
                            @if(in_array($line->status, ['unmatched', 'suggested'], true) && $line->suggested_type)
                                <form method="POST" action="{{ route('workspace.finance.treasury.statements.lines.match', [$statement, $line]) }}">
                                    @csrf
                                    <input type="hidden" name="matched_type" value="{{ $line->suggested_type }}">
                                    <input type="hidden" name="matched_id" value="{{ $line->suggested_id }}">
                                    <button class="text-xs font-semibold text-[#0f7668]">قبول الاقتراح</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-3 py-8 text-center text-slate-500">لا حركات.</td></tr>
                @endforelse
                </tbody>
            </table>
        </article>
    </div>
@endsection
