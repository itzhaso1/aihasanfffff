@extends('layouts.pos', ['pageTitle' => 'المطبخ / طلبات التجهيز'])

@section('content')
    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h2 class="text-base font-bold text-slate-900">طلبات التجهيز للطاولات</h2>
                <p class="mt-1 text-xs text-slate-500">كل طلب مرتبط بطاولة يظهر هنا ليتم تجهيزه ومتابعة حالته.</p>
            </div>
        </div>

        <div class="space-y-3">
            @forelse($orders as $order)
                <article class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p class="text-sm font-bold text-slate-900">{{ $order->table?->name ?: 'طاولة غير معروفة' }}</p>
                            <p class="text-xs text-slate-500">طلب #{{ $order->order_number }} • {{ optional($order->created_at)->format('Y-m-d H:i') }}</p>
                        </div>
                        <form method="POST" action="{{ route('workspace.pos.orders.status', $order) }}" class="flex items-center gap-2">
                            @csrf
                            <select name="pos_status" class="rounded-lg border-slate-300 text-xs">
                                @foreach($posStatuses as $key => $label)
                                    <option value="{{ $key }}" @selected($order->pos_status === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <button class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white">تحديث</button>
                        </form>
                    </div>

                    <ul class="mt-2 space-y-1 text-xs text-slate-700">
                        @foreach($order->items as $item)
                            <li>• {{ $item->quantity }} × {{ $item->product_name }}{{ $item->variant_name ? ' - '.$item->variant_name : '' }}</li>
                        @endforeach
                    </ul>

                    @if($order->notes)
                        <p class="mt-2 rounded-lg bg-white p-2 text-xs text-slate-600">ملاحظات: {{ $order->notes }}</p>
                    @endif
                </article>
            @empty
                <p class="text-sm text-slate-500">لا توجد طلبات تجهيز حالياً.</p>
            @endforelse
        </div>
    </section>
@endsection
