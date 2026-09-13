@extends('layouts.financial', ['pageTitle' => 'المشاريع'])

@section('content')
    <div class="space-y-4">
        <h2 class="text-xl font-bold">المشاريع والربحية</h2>
        <form method="POST" action="{{ route('workspace.finance.projects.store') }}" class="grid gap-2 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2">
            @csrf
            <input name="name" placeholder="اسم المشروع" class="rounded-lg border-slate-300 text-sm" required>
            <select name="customer_id" class="rounded-lg border-slate-300 text-sm">
                <option value="">بدون عميل</option>
                @foreach($customers as $customer)
                    <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                @endforeach
            </select>
            <input type="number" step="0.01" name="budget" placeholder="الميزانية" class="rounded-lg border-slate-300 text-sm">
            <button class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white">إنشاء</button>
        </form>
        <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50"><tr><th class="px-3 py-2 text-right">المشروع</th><th class="px-3 py-2 text-right">الإيراد</th><th class="px-3 py-2 text-right">التكلفة</th><th class="px-3 py-2 text-right">الربح</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse($projects as $project)
                    @php $p = $profit[$project->id] ?? ['revenue' => 0, 'costs' => 0, 'profit' => 0]; @endphp
                    <tr>
                        <td class="px-3 py-2">{{ $project->name }}</td>
                        <td class="px-3 py-2">{{ number_format((float) $p['revenue'], 2) }}</td>
                        <td class="px-3 py-2">{{ number_format((float) $p['costs'], 2) }}</td>
                        <td class="px-3 py-2 font-semibold">{{ number_format((float) $p['profit'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-3 py-8 text-center text-slate-500">لا مشاريع.</td></tr>
                @endforelse
                </tbody>
            </table>
            <div class="p-3">{{ $projects->links() }}</div>
        </article>
    </div>
@endsection
