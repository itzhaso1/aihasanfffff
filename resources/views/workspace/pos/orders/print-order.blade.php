<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>طلب {{ $order->order_number }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-white p-6 text-slate-900">
    <main class="mx-auto max-w-xl">
        <header class="mb-4 border-b pb-3">
            <h1 class="text-lg font-extrabold">{{ $order->workspace?->name }}</h1>
            <p class="text-sm">طلب كاشير: {{ $order->order_number }}</p>
            @if($order->table)
                <p class="text-xs text-slate-500">الطاولة: {{ $order->table->name }}</p>
            @endif
            <p class="text-xs text-slate-500">الوقت: {{ $order->placed_at?->format('Y-m-d H:i') }}</p>
            <p class="text-xs text-slate-500">حالة الدفع: {{ $order->payment_status === 'paid' ? 'مدفوع' : 'غير مدفوع' }}</p>
        </header>

        <table class="w-full text-sm">
            <thead>
                <tr class="border-b">
                    <th class="py-2 text-right">الصنف</th>
                    <th class="py-2 text-right">الكمية</th>
                    <th class="py-2 text-right">السعر</th>
                    <th class="py-2 text-right">الإجمالي</th>
                </tr>
            </thead>
            <tbody>
                @foreach($order->items as $item)
                    <tr class="border-b border-slate-100">
                        <td class="py-2">{{ $item->product_name }}{{ $item->variant_name ? ' - '.$item->variant_name : '' }}</td>
                        <td class="py-2">{{ $item->quantity }}</td>
                        <td class="py-2">{{ number_format((float) $item->unit_price, 2) }}</td>
                        <td class="py-2">{{ number_format((float) $item->total_amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <footer class="mt-4 space-y-1 text-sm">
            <p>Subtotal: {{ number_format((float) $order->subtotal, 2) }} {{ $order->currency }}</p>
            <p>Discount: {{ number_format((float) $order->discount_amount, 2) }} {{ $order->currency }}</p>
            <p class="font-bold">Total: {{ number_format((float) $order->total_amount, 2) }} {{ $order->currency }}</p>
            @if($order->pos_status !== 'cancelled')
                <p class="print:hidden mt-3">
                    <a href="{{ route('workspace.pos.orders.returns.create', $order) }}" class="text-rose-700 underline">مرتجع / استرجاع</a>
                </p>
            @endif
        </footer>
    </main>

    <script>
        window.print();
    </script>
</body>
</html>
