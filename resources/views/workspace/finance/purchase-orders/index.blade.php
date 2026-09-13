@extends('layouts.financial', ['pageTitle' => 'أوامر الشراء'])

@section('content')
    <div class="space-y-4">
        <h2 class="text-xl font-bold">أوامر الشراء</h2>
        <form method="POST" action="{{ route('workspace.finance.purchase-orders.store') }}" class="grid gap-2 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2">
            @csrf
            <select name="supplier_id" class="rounded-lg border-slate-300 text-sm" required>
                <option value="">المورد</option>
                @foreach($suppliers as $supplier)
                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                @endforeach
            </select>
            <input type="date" name="order_date" value="{{ now()->toDateString() }}" class="rounded-lg border-slate-300 text-sm" required>
            <textarea name="items_json" rows="3" class="rounded-lg border-slate-300 text-sm sm:col-span-2" placeholder='[{"product_name":"Item","quantity":1,"unit_price":10,"tax_rate":15}]' required></textarea>
            <button class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white">إنشاء أمر شراء</button>
        </form>

        <article class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50"><tr><th class="px-3 py-2 text-right">الرقم</th><th class="px-3 py-2 text-right">المورد</th><th class="px-3 py-2 text-right">الحالة</th><th class="px-3 py-2 text-right">الإجمالي</th><th class="px-3 py-2 text-right">إجراءات</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                @forelse($orders as $order)
                    <tr>
                        <td class="px-3 py-2 font-semibold">{{ $order->po_number }}</td>
                        <td class="px-3 py-2">{{ $order->supplier?->name }}</td>
                        <td class="px-3 py-2">{{ $order->status }}</td>
                        <td class="px-3 py-2">{{ number_format((float) $order->total, 2) }}</td>
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap gap-2">
                                <form method="POST" action="{{ route('workspace.finance.purchase-orders.submit', $order) }}">@csrf<button class="text-xs font-semibold">إرسال</button></form>
                                <form method="POST" action="{{ route('workspace.finance.purchase-orders.bill', $order) }}">@csrf<button class="text-xs font-semibold">فاتورة</button></form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-3 py-8 text-center text-slate-500">لا أوامر شراء.</td></tr>
                @endforelse
                </tbody>
            </table>
            <div class="p-3">{{ $orders->links() }}</div>
        </article>
    </div>
@endsection
