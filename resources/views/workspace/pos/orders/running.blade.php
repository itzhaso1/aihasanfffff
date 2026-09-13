@extends('layouts.pos', ['pageTitle' => $pageTitle ?? 'الطلبات الجارية'])

@section('content')
    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <h2 class="mb-3 text-base font-bold text-slate-900">{{ $pageTitle ?? 'طلبات QR Menu' }}</h2>
        <p class="mb-4 text-xs text-slate-500">عرض فقط. تشغيل الحالة والدفع يتم من تطبيق الكاشير.</p>

        <div class="space-y-3">
            @forelse($orders as $order)
                <article class="rounded-xl border border-slate-200 p-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="text-sm font-semibold text-slate-900">#{{ $order->order_number }}</p>
                            <p class="text-xs text-slate-500">
                                المصدر: {{ strtoupper($order->source) }}
                                @if($order->table)
                                    • {{ $order->table->name }}
                                @endif
                                • {{ $order->payment_status === 'paid' ? 'مدفوع' : 'غير مدفوع' }}
                            </p>
                        </div>
                        <p class="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">{{ $posStatuses[$order->pos_status] ?? $order->pos_status }}</p>
                    </div>

                    <ul class="mt-2 space-y-1 text-xs text-slate-600">
                        @foreach($order->items as $item)
                            <li>{{ $item->product_name }}{{ $item->variant_name ? ' - '.$item->variant_name : '' }} × {{ $item->quantity }} = {{ number_format((float) $item->total_amount, 2) }}</li>
                        @endforeach
                    </ul>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <a href="{{ route('workspace.pos.orders.print', $order) }}" target="_blank" class="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">طباعة</a>
                        <p class="mr-auto text-sm font-bold text-slate-900">Total: {{ number_format((float) $order->total_amount, 2) }} {{ $order->currency }}</p>
                    </div>
                </article>
            @empty
                <p class="text-sm text-slate-500">لا توجد طلبات حالياً.</p>
            @endforelse
        </div>

        <div class="mt-4">{{ $orders->links() }}</div>
    </section>
@endsection
