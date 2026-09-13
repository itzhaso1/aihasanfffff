@extends('layouts.pos', ['pageTitle' => 'مرتجع طلب POS'])

@section('content')
    <section class="mx-auto max-w-3xl rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="text-base font-bold text-slate-900">تسجيل مرتجع — #{{ $order->order_number }}</h2>
                <p class="text-xs text-slate-500">يسجّل المرتجع للتدقيق فقط دون استدعاء بوابة دفع.</p>
            </div>
            <a href="{{ route('workspace.pos.orders.print', $order) }}" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">العودة للطلب</a>
        </div>

        <form method="POST" action="{{ route('workspace.pos.orders.returns.store', $order) }}" class="space-y-4">
            @csrf
            <div>
                <label class="mb-1 block text-xs font-semibold text-slate-600">سبب المرتجع</label>
                <input type="text" name="reason" value="{{ old('reason') }}" class="w-full rounded-lg border-slate-300 text-sm" placeholder="مثال: خطأ في الطلب / إرجاع عميل" />
            </div>

            <div class="space-y-2">
                <h3 class="text-sm font-bold text-slate-800">عناصر المرتجع</h3>
                @foreach($order->items as $index => $item)
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm">
                        <p class="font-semibold text-slate-900">{{ $item->product_name }}{{ $item->variant_name ? ' — '.$item->variant_name : '' }}</p>
                        <p class="text-xs text-slate-500">الكمية الأصلية: {{ $item->quantity }} • السعر: {{ number_format((float) $item->unit_price, 2) }}</p>
                        <input type="hidden" name="items[{{ $index }}][order_item_id]" value="{{ $item->id }}" />
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold text-slate-600">كمية المرتجع</label>
                                <input type="number" min="1" max="{{ $item->quantity }}" name="items[{{ $index }}][qty]" value="{{ old('items.'.$index.'.qty', $item->quantity) }}" class="w-full rounded-lg border-slate-300 text-sm" />
                            </div>
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold text-slate-600">المبلغ (اختياري)</label>
                                <input type="number" step="0.01" min="0" name="items[{{ $index }}][amount]" value="{{ old('items.'.$index.'.amount') }}" placeholder="{{ number_format((float) $item->total_amount, 2) }}" class="w-full rounded-lg border-slate-300 text-sm" />
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" name="mark_refunded" value="1" @checked(old('mark_refunded')) />
                <span>تعيين كمسترجع فورًا (تحديث حالة الدفع / تدقيق فقط)</span>
            </label>

            <button class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700">حفظ المرتجع</button>
        </form>
    </section>
@endsection
