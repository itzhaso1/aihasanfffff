@extends('layouts.financial', ['pageTitle' => 'رمز QR '.$invoice->invoice_number])

@section('content')
    @php
        $tags = $qr['tags'] ?? [];
    @endphp
    <div class="mx-auto max-w-xl space-y-4">
        <div class="flex items-center justify-between gap-2">
            <h2 class="text-xl font-bold text-slate-900">رمز QR للفاتورة {{ $invoice->invoice_number }}</h2>
            <a href="{{ route('workspace.finance.invoices.show', $invoice) }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">رجوع</a>
        </div>
        <p class="text-xs leading-5 text-slate-500">هذا رمز TLV الداخلي المولّد من لقطة الإصدار. ليس اعتماد FATOORA ولا ختم إنتاج.</p>
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-slate-500">البائع</dt><dd>{{ $tags['seller_name'] ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">الرقم الضريبي</dt><dd>{{ $tags['seller_vat'] ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">الوقت</dt><dd>{{ $tags['timestamp'] ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">الإجمالي شامل الضريبة</dt><dd>{{ $tags['total_with_vat'] ?? '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">الضريبة</dt><dd>{{ $tags['vat_total'] ?? '—' }}</dd></div>
            </dl>
            <p class="mt-4 break-all text-[11px] text-slate-500">TLV: {{ $qr['qr_base64'] ?? '' }}</p>
        </div>
    </div>
@endsection
