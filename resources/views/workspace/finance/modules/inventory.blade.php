@extends('layouts.financial', ['pageTitle' => 'حركات المخزون'])

@section('content')
    <div class="space-y-4">
        <h2 class="text-xl font-bold text-slate-900">حركات المخزون</h2>
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="px-3 py-3 text-right">المنتج</th>
                        <th class="px-3 py-3 text-right">النوع</th>
                        <th class="px-3 py-3 text-right">الكمية</th>
                        <th class="px-3 py-3 text-right">قبل</th>
                        <th class="px-3 py-3 text-right">بعد</th>
                        <th class="px-3 py-3 text-right">المرجع</th>
                        <th class="px-3 py-3 text-right">التاريخ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($movements as $movement)
                        <tr>
                            <td class="px-3 py-3">{{ $movement->product?->name ?? '-' }}</td>
                            <td class="px-3 py-3">{{ $movement->type }}</td>
                            <td class="px-3 py-3">{{ $movement->quantity }}</td>
                            <td class="px-3 py-3">{{ $movement->before_quantity }}</td>
                            <td class="px-3 py-3">{{ $movement->after_quantity }}</td>
                            <td class="px-3 py-3">{{ $movement->reference_type }}#{{ $movement->reference_id }}</td>
                            <td class="px-3 py-3">{{ $movement->created_at }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-8 text-center text-slate-500">لا توجد حركات مخزون.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $movements->links() }}</div>
    </div>
@endsection
