<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>فاتورة {{ $invoice->invoice_number }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-white p-6 text-slate-900">
    <main class="mx-auto max-w-xl">
        <header class="mb-4 border-b pb-3">
            <h1 class="text-lg font-extrabold">{{ $invoice->workspace?->name }}</h1>
            <p class="text-sm">فاتورة كاشير: {{ $invoice->invoice_number }}</p>
            <p class="text-xs text-slate-500">الطاولة: {{ $invoice->table?->name ?: '—' }}</p>
            <p class="text-xs text-slate-500">الموظف: {{ $invoice->closer?->name ?: '—' }}</p>
            <p class="text-xs text-slate-500">وقت الإغلاق: {{ $invoice->closed_at?->format('Y-m-d H:i') }}</p>
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
                @foreach($invoice->items as $item)
                    <tr class="border-b border-slate-100">
                        <td class="py-2">{{ $item->item_name }}{{ $item->size_label ? ' - '.$item->size_label : '' }}</td>
                        <td class="py-2">{{ $item->quantity }}</td>
                        <td class="py-2">{{ number_format((float) $item->unit_price, 2) }}</td>
                        <td class="py-2">{{ number_format((float) $item->total_amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <footer class="mt-4 space-y-1 text-sm">
            <p>Subtotal: {{ number_format((float) $invoice->subtotal, 2) }} {{ $invoice->currency }}</p>
            <p>Discount: {{ number_format((float) $invoice->discount_amount, 2) }} {{ $invoice->currency }}</p>
            <p class="font-bold">Total: {{ number_format((float) $invoice->total_amount, 2) }} {{ $invoice->currency }}</p>
        </footer>
    </main>

    <script>
        window.print();
    </script>
</body>
</html>
