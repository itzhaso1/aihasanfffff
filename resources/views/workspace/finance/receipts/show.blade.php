@extends('layouts.financial', ['pageTitle' => 'إيصال '.$receipt->receipt_number])

@php
    $payment = $receipt->payment;
    $invoice = $receipt->invoice;
    $amount = (float) ($payment?->amount ?? $receipt->amount);
    $deliveryStatusLabels = ['sending' => 'جارٍ الإرسال', 'sent' => 'تم الإرسال', 'failed' => 'فشل الإرسال'];
    $sendEmail = old('email', $receipt->customer?->email);
@endphp

@section('content')
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold text-slate-900">إيصال {{ $receipt->receipt_number }}</h2>
                <p class="mt-1 text-xs text-slate-500">مرتبط بالدفعة #{{ $receipt->payment_id }} على الفاتورة {{ $invoice?->invoice_number }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <span class="rounded-full bg-slate-50 px-3 py-1 text-xs font-bold text-slate-600">مستند: {{ $receipt->isVoided() ? 'ملغى' : 'معتمد' }}</span>
                    <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">مبلغ الدفعة: {{ number_format($amount, 2) }} {{ $receipt->currency }}</span>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('workspace.finance.receipts.index') }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700">رجوع</a>
                <a href="{{ route('workspace.finance.receipts.pdf', $receipt) }}" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white">PDF</a>
                @if($invoice)
                    <a href="{{ route('workspace.finance.invoices.show', $invoice) }}" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700">الفاتورة</a>
                @endif
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-bold">بيانات التحصيل</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">العميل</dt><dd>{{ $receipt->customer?->name ?: '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">تاريخ الدفع</dt><dd>{{ optional($payment?->payment_date ?? $receipt->payment_date)->format('Y-m-d') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">الطريقة</dt><dd>{{ $payment?->method ?: $receipt->method }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">المرجع</dt><dd>{{ $payment?->reference ?: ($receipt->reference ?: '—') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">المبلغ من الدفعة</dt><dd class="font-bold">{{ number_format($amount, 2) }} {{ $receipt->currency }}</dd></div>
                </dl>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-bold">إرسال الإيصال بالبريد</h3>
                @if($receipt->isVoided())
                    <p class="mt-2 text-sm text-slate-500">لا يمكن إرسال إيصال ملغى.</p>
                @else
                    <form method="POST" action="{{ route('workspace.finance.receipts.send', $receipt) }}" class="mt-3 space-y-3">
                        @csrf
                        <input type="email" name="email" value="{{ $sendEmail }}" required class="w-full rounded-lg border-slate-300 text-sm" placeholder="customer@example.com">
                        <input type="text" name="subject" value="{{ old('subject', 'إيصال رقم '.$receipt->receipt_number) }}" class="w-full rounded-lg border-slate-300 text-sm">
                        <textarea name="message" rows="4" class="w-full rounded-lg border-slate-300 text-sm">{{ old('message') }}</textarea>
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                            <input type="hidden" name="attach_pdf" value="0">
                            <input type="checkbox" name="attach_pdf" value="1" class="rounded border-slate-300 text-[#06C2A4]" checked>
                            إرفاق ملف PDF
                        </label>
                        <button class="rounded-lg bg-[#06C2A4] px-4 py-2 text-sm font-semibold text-white">إرسال عبر البريد</button>
                    </form>
                @endif
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-sm font-bold">سجل الإرسال</h3>
            <table class="mt-3 min-w-full text-sm">
                <thead class="text-slate-500">
                    <tr>
                        <th class="py-1 text-right">التاريخ</th>
                        <th class="py-1 text-right">المستلم</th>
                        <th class="py-1 text-right">الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($receipt->deliveries as $delivery)
                        <tr class="border-t border-slate-100">
                            <td class="py-2">{{ ($delivery->sent_at ?? $delivery->created_at)?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                            <td class="py-2">{{ $delivery->recipient }}</td>
                            <td class="py-2">{{ $deliveryStatusLabels[$delivery->status] ?? $delivery->status }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-6 text-center text-slate-500">لم يُرسل بعد.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
