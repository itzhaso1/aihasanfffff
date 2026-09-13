@extends('layouts.financial', ['pageTitle' => 'العملاء المحتملون'])

@section('content')
    <div class="space-y-4">
        <h2 class="text-xl font-bold">CRM — العملاء المحتملون</h2>
        <form method="POST" action="{{ route('workspace.finance.leads.store') }}" class="grid gap-2 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2">
            @csrf
            <input name="name" placeholder="الاسم" class="rounded-lg border-slate-300 text-sm" required>
            <input name="company_name" placeholder="الشركة" class="rounded-lg border-slate-300 text-sm">
            <input name="email" type="email" placeholder="البريد" class="rounded-lg border-slate-300 text-sm">
            <input name="phone" placeholder="الهاتف" class="rounded-lg border-slate-300 text-sm">
            <button class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white">إضافة</button>
        </form>
        <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50"><tr><th class="px-3 py-2 text-right">الاسم</th><th class="px-3 py-2 text-right">الحالة</th><th class="px-3 py-2 text-right">إجراء</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse($leads as $lead)
                    <tr>
                        <td class="px-3 py-2">{{ $lead->name }}</td>
                        <td class="px-3 py-2">{{ $lead->status }}</td>
                        <td class="px-3 py-2">
                            <form method="POST" action="{{ route('workspace.finance.leads.convert', $lead) }}">@csrf<button class="text-xs font-semibold text-[#0f7668]">تحويل إلى عميل</button></form>
                        </td>
                    </tr>
                @empty
                    <tr><td class="px-3 py-8 text-center text-slate-500">لا عملاء محتملين.</td></tr>
                @endforelse
                </tbody>
            </table>
            <div class="p-3">{{ $leads->links() }}</div>
        </article>
    </div>
@endsection
